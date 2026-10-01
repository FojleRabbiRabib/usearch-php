/*
 * usearch-php — the Usearch\Index class: lifecycle, mutations, and queries.
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

#include "usearch_internal.h"
#include "usearch_arginfo.h"

zend_class_entry *usearch_ce_index;
static zend_object_handlers usearch_index_handlers;

static zend_object *usearch_index_create_object(zend_class_entry *ce)
{
	usearch_index_object *intern = zend_object_alloc(sizeof(usearch_index_object), ce);
	intern->handle = NULL;
	intern->read_only = false;
	intern->metric_kind = usearch_metric_cos_k;
	zend_object_std_init(&intern->std, ce);
	object_properties_init(&intern->std, ce);
	intern->std.handlers = &usearch_index_handlers;
	return &intern->std;
}

static void usearch_index_free_object(zend_object *obj)
{
	usearch_index_object *intern = usearch_index_from_obj(obj);
	if (intern->handle != NULL) {
		usearch_error_t error = NULL;
		usearch_free((usearch_index_t)intern->handle, &error);
		intern->handle = NULL;
	}
	zend_object_std_dtor(&intern->std);
}

void *usearch_index_require_handle(usearch_index_object *intern)
{
	if (intern->handle == NULL) {
		usearch_throw_no_handle();
		return NULL;
	}
	return intern->handle;
}

/* -------------------------------------------------------------------------
 * Public methods
 * ------------------------------------------------------------------------- */

PHP_METHOD(Usearch_Index, __construct)
{
	(void)return_value; /* void method */
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	HashTable *options = NULL;
	usearch_init_options_t opts;
	usearch_error_t error = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_ARRAY_HT(options)
	ZEND_PARSE_PARAMETERS_END();

	/* Start from clean library defaults. */
	memset(&opts, 0, sizeof(opts));
	opts.metric_kind = usearch_metric_cos_k;
	opts.quantization = usearch_scalar_f32_k;
	opts.dimensions = 0;
	opts.connectivity = 16;
	opts.expansion_add = 128;
	opts.expansion_search = 64;
	opts.multi = false;

	if (options != NULL) {
		zend_string *key;
		zval *val;

		ZEND_HASH_FOREACH_STR_KEY_VAL(options, key, val)
		{
			if (key == NULL) {
				zend_throw_error(zend_ce_value_error, "options must be an associative array");
				return;
			}
			ZVAL_DEREF(val);

			if (zend_string_equals_literal(key, "dimensions")) {
				if (Z_TYPE_P(val) != IS_LONG || Z_LVAL_P(val) <= 0) {
					zend_throw_error(zend_ce_value_error, "dimensions must be a positive integer");
					return;
				}
				opts.dimensions = (size_t)Z_LVAL_P(val);
			} else if (zend_string_equals_literal(key, "metric")) {
				zend_long m;
				if (usearch_metric_from_zval(val, &m) == FAILURE) {
					return;
				}
				opts.metric_kind = (usearch_metric_kind_t)m;
			} else if (zend_string_equals_literal(key, "quantization")) {
				zend_long q;
				if (usearch_scalar_from_zval(val, &q) == FAILURE) {
					return;
				}
				opts.quantization = (usearch_scalar_kind_t)q;
			} else if (zend_string_equals_literal(key, "connectivity")) {
				if (Z_TYPE_P(val) != IS_LONG || Z_LVAL_P(val) < 0) {
					zend_throw_error(zend_ce_value_error, "connectivity must be a non-negative integer");
					return;
				}
				opts.connectivity = (size_t)Z_LVAL_P(val);
			} else if (zend_string_equals_literal(key, "expansionAdd")) {
				if (Z_TYPE_P(val) != IS_LONG || Z_LVAL_P(val) < 0) {
					zend_throw_error(zend_ce_value_error, "expansionAdd must be a non-negative integer");
					return;
				}
				opts.expansion_add = (size_t)Z_LVAL_P(val);
			} else if (zend_string_equals_literal(key, "expansionSearch")) {
				if (Z_TYPE_P(val) != IS_LONG || Z_LVAL_P(val) < 0) {
					zend_throw_error(zend_ce_value_error, "expansionSearch must be a non-negative integer");
					return;
				}
				opts.expansion_search = (size_t)Z_LVAL_P(val);
			} else if (zend_string_equals_literal(key, "multi")) {
				opts.multi = zend_is_true(val);
			} else {
				zend_throw_error(zend_ce_value_error, "unknown option '%s'", ZSTR_VAL(key));
				return;
			}
		}
		ZEND_HASH_FOREACH_END();
	}

	if (opts.dimensions == 0 && opts.metric_kind != usearch_metric_haversine_k) {
		zend_throw_error(zend_ce_value_error, "dimensions is required and must be greater than 0");
		return;
	}

	intern->handle = usearch_init(&opts, &error);
	if (usearch_check_error(&error) == FAILURE) {
		intern->handle = NULL;
		return;
	}
	intern->metric_kind = (zend_long)opts.metric_kind;
}

PHP_METHOD(Usearch_Index, add)
{
	(void)return_value; /* void method */
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long key;
	zval *zv_vector;
	float *data;
	bool owned = false;
	usearch_error_t error = NULL;
	size_t dims;

	if (handle == NULL) {
		return;
	}
	if (usearch_reject_if_read_only(intern) == FAILURE) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(2, 2)
	Z_PARAM_LONG(key)
	Z_PARAM_ZVAL(zv_vector)
	ZEND_PARSE_PARAMETERS_END();

	dims = usearch_dimensions((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}

	if (usearch_vector_in(zv_vector, dims, &data, &owned) == FAILURE) {
		return;
	}

	/* Upstream requires capacity to be reserved ahead of any insertion and
	 * reports a plain error otherwise. A caller inserting one vector at a time
	 * should not have to track that, so grow geometrically here: amortised O(1)
	 * per insert, and a caller who pre-sized with reserve() pays nothing. */
	{
		size_t size = usearch_size((usearch_index_t)handle, &error);
		size_t capacity = usearch_capacity((usearch_index_t)handle, &error);
		if (usearch_check_error(&error) == FAILURE) {
			if (owned) {
				efree(data);
			}
			return;
		}
		if (size + 1 > capacity) {
			size_t grown = capacity < USEARCH_MIN_CAPACITY ? USEARCH_MIN_CAPACITY : capacity * 2;
			usearch_reserve((usearch_index_t)handle, grown, &error);
			if (usearch_check_error(&error) == FAILURE) {
				if (owned) {
					efree(data);
				}
				return;
			}
		}
	}

	usearch_add((usearch_index_t)handle, (usearch_key_t)key, data, usearch_scalar_f32_k, &error);
	if (owned) {
		efree(data);
	}
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
}

PHP_METHOD(Usearch_Index, search)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zval *zv_query;
	zend_long count = 10;
	float *data;
	bool owned = false;
	usearch_error_t error = NULL;
	size_t dims, found;
	usearch_key_t *keys;
	usearch_distance_t *distances;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 2)
	Z_PARAM_ZVAL(zv_query)
	Z_PARAM_OPTIONAL
	Z_PARAM_LONG(count)
	ZEND_PARSE_PARAMETERS_END();

	if (count <= 0) {
		zend_throw_error(zend_ce_value_error, "count must be a positive integer");
		return;
	}

	dims = usearch_dimensions((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}

	if (usearch_vector_in(zv_query, dims, &data, &owned) == FAILURE) {
		return;
	}

	keys = (usearch_key_t *)safe_emalloc((size_t)count, sizeof(usearch_key_t), 0);
	distances = (usearch_distance_t *)safe_emalloc((size_t)count, sizeof(usearch_distance_t), 0);

	found = usearch_search((usearch_index_t)handle, data, usearch_scalar_f32_k,
						   (size_t)count, keys, distances, &error);

	if (owned) {
		efree(data);
	}

	if (usearch_check_error(&error) == FAILURE) {
		efree(keys);
		efree(distances);
		return;
	}

	array_init_size(return_value, (uint32_t)found);
	for (size_t i = 0; i < found; i++) {
		zval row;
		array_init_size(&row, 2);
		add_assoc_long_ex(&row, "key", sizeof("key") - 1, (zend_long)keys[i]);
		add_assoc_double_ex(&row, "distance", sizeof("distance") - 1, (double)distances[i]);
		add_next_index_zval(return_value, &row);
	}

	efree(keys);
	efree(distances);
}

PHP_METHOD(Usearch_Index, get)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long key;
	usearch_error_t error = NULL;
	size_t dims, recovered;
	float *data;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_LONG(key)
	ZEND_PARSE_PARAMETERS_END();

	if (!usearch_contains((usearch_index_t)handle, (usearch_key_t)key, &error)) {
		if (usearch_check_error(&error) == FAILURE) {
			return;
		}
		RETURN_NULL();
	}

	dims = usearch_dimensions((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}

	data = (float *)safe_emalloc(dims, sizeof(float), 0);
	recovered = usearch_get((usearch_index_t)handle, (usearch_key_t)key, 1,
							data, usearch_scalar_f32_k, &error);

	if (usearch_check_error(&error) == FAILURE || recovered == 0) {
		efree(data);
		RETURN_NULL();
	}

	usearch_vector_out(data, dims, return_value);
	efree(data);
}

PHP_METHOD(Usearch_Index, contains)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long key;
	usearch_error_t error = NULL;
	bool res;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_LONG(key)
	ZEND_PARSE_PARAMETERS_END();

	res = usearch_contains((usearch_index_t)handle, (usearch_key_t)key, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_BOOL(res);
}

PHP_METHOD(Usearch_Index, count)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long key;
	usearch_error_t error = NULL;
	size_t c;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_LONG(key)
	ZEND_PARSE_PARAMETERS_END();

	c = usearch_count((usearch_index_t)handle, (usearch_key_t)key, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)c);
}

PHP_METHOD(Usearch_Index, remove)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long key;
	usearch_error_t error = NULL;
	size_t removed;

	if (handle == NULL) {
		return;
	}
	if (usearch_reject_if_read_only(intern) == FAILURE) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_LONG(key)
	ZEND_PARSE_PARAMETERS_END();

	removed = usearch_remove((usearch_index_t)handle, (usearch_key_t)key, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)removed);
}

PHP_METHOD(Usearch_Index, rename)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long from, to;
	usearch_error_t error = NULL;
	size_t renamed;

	if (handle == NULL) {
		return;
	}
	if (usearch_reject_if_read_only(intern) == FAILURE) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(2, 2)
	Z_PARAM_LONG(from)
	Z_PARAM_LONG(to)
	ZEND_PARSE_PARAMETERS_END();

	renamed = usearch_rename((usearch_index_t)handle, (usearch_key_t)from, (usearch_key_t)to, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)renamed);
}

PHP_METHOD(Usearch_Index, clear)
{
	(void)return_value; /* void method */
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}
	if (usearch_reject_if_read_only(intern) == FAILURE) {
		return;
	}

	ZEND_PARSE_PARAMETERS_NONE();

	usearch_clear((usearch_index_t)handle, &error);
	usearch_check_error(&error);
}

PHP_METHOD(Usearch_Index, reserve)
{
	(void)return_value; /* void method */
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long cap;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}
	if (usearch_reject_if_read_only(intern) == FAILURE) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_LONG(cap)
	ZEND_PARSE_PARAMETERS_END();

	if (cap < 0) {
		zend_throw_error(zend_ce_value_error, "capacity must be non-negative");
		return;
	}

	usearch_reserve((usearch_index_t)handle, (size_t)cap, &error);
	usearch_check_error(&error);
}

PHP_METHOD(Usearch_Index, save)
{
	(void)return_value; /* void method */
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	char *path;
	size_t path_len;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_PATH(path, path_len)
	ZEND_PARSE_PARAMETERS_END();

	usearch_save((usearch_index_t)handle, path, &error);
	usearch_check_error(&error);
}

PHP_METHOD(Usearch_Index, load)
{
	(void)return_value; /* void method */
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	char *path;
	size_t path_len;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_PATH(path, path_len)
	ZEND_PARSE_PARAMETERS_END();

	usearch_load((usearch_index_t)handle, path, &error);
	if (usearch_check_error(&error) == SUCCESS) {
		usearch_init_options_t stored;
		intern->read_only = false;
		/* A loaded index carries its own metric, which need not match the one
		 * this object was constructed with. Re-read it so distance() keeps
		 * agreeing with search() after a reload. */
		memset(&stored, 0, sizeof(stored));
		usearch_metadata(path, &stored, &error);
		if (usearch_check_error(&error) == SUCCESS) {
			intern->metric_kind = (zend_long)stored.metric_kind;
		}
	}
}

PHP_METHOD(Usearch_Index, view)
{
	(void)return_value; /* void method */
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	char *path;
	size_t path_len;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_PATH(path, path_len)
	ZEND_PARSE_PARAMETERS_END();

	usearch_view((usearch_index_t)handle, path, &error);
	if (usearch_check_error(&error) == SUCCESS) {
		usearch_init_options_t stored;
		intern->read_only = true;
		/* Same rationale as load(): the viewed file's metric is authoritative. */
		memset(&stored, 0, sizeof(stored));
		usearch_metadata(path, &stored, &error);
		if (usearch_check_error(&error) == SUCCESS) {
			intern->metric_kind = (zend_long)stored.metric_kind;
		}
	}
}

PHP_METHOD(Usearch_Index, size)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	usearch_error_t error = NULL;
	size_t s;

	if (handle == NULL) {
		return;
	}
	ZEND_PARSE_PARAMETERS_NONE();

	s = usearch_size((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)s);
}

PHP_METHOD(Usearch_Index, capacity)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	usearch_error_t error = NULL;
	size_t c;

	if (handle == NULL) {
		return;
	}
	ZEND_PARSE_PARAMETERS_NONE();

	c = usearch_capacity((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)c);
}

PHP_METHOD(Usearch_Index, dimensions)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	usearch_error_t error = NULL;
	size_t d;

	if (handle == NULL) {
		return;
	}
	ZEND_PARSE_PARAMETERS_NONE();

	d = usearch_dimensions((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)d);
}

PHP_METHOD(Usearch_Index, connectivity)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	usearch_error_t error = NULL;
	size_t c;

	if (handle == NULL) {
		return;
	}
	ZEND_PARSE_PARAMETERS_NONE();

	c = usearch_connectivity((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)c);
}

PHP_METHOD(Usearch_Index, expansionAdd)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long exp = -1;
	bool exp_is_null = true;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_LONG_OR_NULL(exp, exp_is_null)
	ZEND_PARSE_PARAMETERS_END();

	if (!exp_is_null) {
		if (usearch_reject_if_read_only(intern) == FAILURE) {
			return;
		}
		if (exp < 0) {
			zend_throw_error(zend_ce_value_error, "expansion must be non-negative");
			return;
		}
		usearch_change_expansion_add((usearch_index_t)handle, (size_t)exp, &error);
		if (usearch_check_error(&error) == FAILURE) {
			return;
		}
	}

	RETURN_LONG((zend_long)usearch_expansion_add((usearch_index_t)handle, &error));
}

PHP_METHOD(Usearch_Index, expansionSearch)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long exp = -1;
	bool exp_is_null = true;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_LONG_OR_NULL(exp, exp_is_null)
	ZEND_PARSE_PARAMETERS_END();

	if (!exp_is_null) {
		if (exp < 0) {
			zend_throw_error(zend_ce_value_error, "expansion must be non-negative");
			return;
		}
		usearch_change_expansion_search((usearch_index_t)handle, (size_t)exp, &error);
		if (usearch_check_error(&error) == FAILURE) {
			return;
		}
	}

	RETURN_LONG((zend_long)usearch_expansion_search((usearch_index_t)handle, &error));
}

PHP_METHOD(Usearch_Index, threadsAdd)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long th = -1;
	bool th_is_null = true;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_LONG_OR_NULL(th, th_is_null)
	ZEND_PARSE_PARAMETERS_END();

	if (!th_is_null) {
		if (th < 0) {
			zend_throw_error(zend_ce_value_error, "threads must be non-negative");
			return;
		}
		usearch_change_threads_add((usearch_index_t)handle, (size_t)th, &error);
		if (usearch_check_error(&error) == FAILURE) {
			return;
		}
	}

	/* Upstream exposes change_threads_add without a thread count getter;
	 * return the set value or 0 when unchanged. */
	RETURN_LONG(th_is_null ? 0 : th);
}

PHP_METHOD(Usearch_Index, threadsSearch)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zend_long th = -1;
	bool th_is_null = true;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_LONG_OR_NULL(th, th_is_null)
	ZEND_PARSE_PARAMETERS_END();

	if (!th_is_null) {
		if (th < 0) {
			zend_throw_error(zend_ce_value_error, "threads must be non-negative");
			return;
		}
		usearch_change_threads_search((usearch_index_t)handle, (size_t)th, &error);
		if (usearch_check_error(&error) == FAILURE) {
			return;
		}
	}

	RETURN_LONG(th_is_null ? 0 : th);
}

PHP_METHOD(Usearch_Index, changeMetric)
{
	(void)return_value; /* void method */
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zval *zv_metric;
	zend_long m;
	usearch_error_t error = NULL;

	if (handle == NULL) {
		return;
	}
	if (usearch_reject_if_read_only(intern) == FAILURE) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(zv_metric)
	ZEND_PARSE_PARAMETERS_END();

	if (usearch_metric_from_zval(zv_metric, &m) == FAILURE) {
		return;
	}

	usearch_change_metric_kind((usearch_index_t)handle, (usearch_metric_kind_t)m, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	intern->metric_kind = m;
}

PHP_METHOD(Usearch_Index, memoryUsage)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	usearch_error_t error = NULL;
	size_t m;

	if (handle == NULL) {
		return;
	}
	ZEND_PARSE_PARAMETERS_NONE();

	m = usearch_memory_usage((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)m);
}

PHP_METHOD(Usearch_Index, serializedLength)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	usearch_error_t error = NULL;
	size_t len;

	if (handle == NULL) {
		return;
	}
	ZEND_PARSE_PARAMETERS_NONE();

	len = usearch_serialized_length((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_LONG((zend_long)len);
}

PHP_METHOD(Usearch_Index, hardwareAcceleration)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	usearch_error_t error = NULL;
	const char *isa;

	if (handle == NULL) {
		return;
	}
	ZEND_PARSE_PARAMETERS_NONE();

	isa = usearch_hardware_acceleration((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_STRING(isa != NULL ? isa : "");
}

PHP_METHOD(Usearch_Index, distance)
{
	usearch_index_object *intern = Z_USEARCH_INDEX_P(ZEND_THIS);
	void *handle = usearch_index_require_handle(intern);
	zval *za, *zb;
	float *buf_a, *buf_b;
	bool owned_a = false, owned_b = false;
	usearch_error_t error = NULL;
	size_t dims;
	usearch_distance_t d;

	if (handle == NULL) {
		return;
	}

	ZEND_PARSE_PARAMETERS_START(2, 2)
	Z_PARAM_ZVAL(za)
	Z_PARAM_ZVAL(zb)
	ZEND_PARSE_PARAMETERS_END();

	dims = usearch_dimensions((usearch_index_t)handle, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}

	if (usearch_vector_in(za, dims, &buf_a, &owned_a) == FAILURE) {
		return;
	}
	if (usearch_vector_in(zb, dims, &buf_b, &owned_b) == FAILURE) {
		if (owned_a)
			efree(buf_a);
		return;
	}

	d = usearch_distance(buf_a, buf_b, usearch_scalar_f32_k, dims,
						 (usearch_metric_kind_t)intern->metric_kind, &error);

	if (owned_a)
		efree(buf_a);
	if (owned_b)
		efree(buf_b);

	if (usearch_check_error(&error) == FAILURE) {
		return;
	}
	RETURN_DOUBLE((double)d);
}

PHP_METHOD(Usearch_Index, metadata)
{
	char *path;
	size_t path_len;
	usearch_init_options_t opts;
	usearch_error_t error = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_PATH(path, path_len)
	ZEND_PARSE_PARAMETERS_END();

	memset(&opts, 0, sizeof(opts));
	usearch_metadata(path, &opts, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}

	array_init_size(return_value, 7);
	add_assoc_long_ex(return_value, "metric", sizeof("metric") - 1, (zend_long)opts.metric_kind);
	add_assoc_long_ex(return_value, "quantization", sizeof("quantization") - 1, (zend_long)opts.quantization);
	add_assoc_long_ex(return_value, "dimensions", sizeof("dimensions") - 1, (zend_long)opts.dimensions);
	add_assoc_long_ex(return_value, "connectivity", sizeof("connectivity") - 1, (zend_long)opts.connectivity);
	add_assoc_long_ex(return_value, "expansionAdd", sizeof("expansionAdd") - 1, (zend_long)opts.expansion_add);
	add_assoc_long_ex(return_value, "expansionSearch", sizeof("expansionSearch") - 1, (zend_long)opts.expansion_search);
	add_assoc_bool_ex(return_value, "multi", sizeof("multi") - 1, opts.multi);
}

PHP_METHOD(Usearch_Index, version)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_STRING(PHP_USEARCH_VERSION "+usearch." PHP_USEARCH_VENDORED);
}

PHP_METHOD(Usearch_Index, hardwareAccelerationCompiled)
{
	const char *isa;
	ZEND_PARSE_PARAMETERS_NONE();
	isa = usearch_hardware_acceleration_compiled();
	RETURN_STRING(isa != NULL ? isa : "");
}

PHP_METHOD(Usearch_Index, hardwareAccelerationAvailable)
{
	const char *isa;
	ZEND_PARSE_PARAMETERS_NONE();
	isa = usearch_hardware_acceleration_available();
	RETURN_STRING(isa != NULL ? isa : "");
}

/* -------------------------------------------------------------------------
 * Class registration
 * ------------------------------------------------------------------------- */

void usearch_register_index(void)
{
	zend_class_entry ce;
	INIT_CLASS_ENTRY(ce, "Usearch\\Index", class_Usearch_Index_methods);
	usearch_ce_index = zend_register_internal_class(&ce);
	usearch_ce_index->create_object = usearch_index_create_object;

	memcpy(&usearch_index_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
	usearch_index_handlers.offset = XtOffsetOf(usearch_index_object, std);
	usearch_index_handlers.free_obj = usearch_index_free_object;
}

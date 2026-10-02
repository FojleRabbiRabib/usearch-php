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
	intern->scalar_kind = usearch_scalar_f32_k;
	intern->threads_add = 0;
	intern->threads_search = 0;
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

/* The engine's default clone copies the handle verbatim, so both objects call
 * usearch_free on the same native index and the second one faults. Cloning is
 * a caller error, not a supported operation: a shallow copy double-frees and a
 * real deep copy would need upstream's serialise/reload path, which silently
 * dequantises non-F32 vectors.
 *
 * The refusal is `clone_obj = NULL` in the handlers below, never a throwing
 * clone_obj function: the engine checks the handler pointer before calling it
 * and raises "Trying to clone an uncloneable object of class Usearch\Index"
 * itself, but a handler that throws and returns NULL is not checked after the
 * call — the engine dereferences the returned NULL one opcode later. */

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
	intern->scalar_kind = (zend_long)opts.quantization;
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

	dims = usearch_effective_dims(handle, intern->metric_kind, &error);
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

	dims = usearch_effective_dims(handle, intern->metric_kind, &error);
	if (usearch_check_error(&error) == FAILURE) {
		return;
	}

	if (usearch_vector_in(zv_query, dims, &data, &owned) == FAILURE) {
		return;
	}

	/* Upstream can never return more than the index holds, and sizing the
	 * output buffers from raw caller input lets PHP_INT_MAX overflow
	 * safe_emalloc into an uncatchable fatal that kills the fpm worker.
	 * Clamping is both the safety fix and the cheaper allocation. */
	{
		size_t have = usearch_size((usearch_index_t)handle, &error);
		if (usearch_check_error(&error) == FAILURE) {
			if (owned) {
				efree(data);
			}
			return;
		}
		if ((size_t)count > have) {
			count = (zend_long)have;
		}
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

	dims = usearch_effective_dims(handle, intern->metric_kind, &error);
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
		/* The loaded handle's budgets come from the file, not from anything
		 * this object set, and upstream exposes no getter to re-read them.
		 * Reset the retained readback to 0 ("unset by this object") so it
		 * cannot keep reporting a value the new handle does not have. */
		intern->threads_add = 0;
		intern->threads_search = 0;
		/* A loaded index carries its own metric, which need not match the one
		 * this object was constructed with. Re-read it so distance() keeps
		 * agreeing with search() after a reload. */
		memset(&stored, 0, sizeof(stored));
		usearch_metadata(path, &stored, &error);
		if (usearch_check_error(&error) == SUCCESS) {
			intern->metric_kind = (zend_long)stored.metric_kind;
			intern->scalar_kind = (zend_long)stored.quantization;
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
		/* Same readback reset as load(): the view's budgets came from the
		 * file, and upstream sets at least 1 when it opens a view, so a
		 * retained 4 or a fresh 0 would both misdescribe it. */
		intern->threads_add = 0;
		intern->threads_search = 0;
		/* Same rationale as load(): the viewed file's metric is authoritative. */
		memset(&stored, 0, sizeof(stored));
		usearch_metadata(path, &stored, &error);
		if (usearch_check_error(&error) == SUCCESS) {
			intern->metric_kind = (zend_long)stored.metric_kind;
			intern->scalar_kind = (zend_long)stored.quantization;
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
		if (usearch_reject_if_read_only(intern) == FAILURE) {
			return;
		}
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
		/* Raising the thread budget makes upstream reallocate its node and
		 * context buffers, which materialises a memory-mapped view into a
		 * private heap copy. Guard it like every other mutation. */
		if (usearch_reject_if_read_only(intern) == FAILURE) {
			return;
		}
		if (th < 0) {
			zend_throw_error(zend_ce_value_error, "threads must be non-negative");
			return;
		}
		usearch_change_threads_add((usearch_index_t)handle, (size_t)th, &error);
		if (usearch_check_error(&error) == FAILURE) {
			return;
		}
		intern->threads_add = th;
	}

	/* Upstream exposes change_threads_add without a getter, so the truthful
	 * read-back comes from the retained value; 0 means automatic. */
	RETURN_LONG(intern->threads_add);
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
		/* Same rationale as threadsAdd: a raised search budget reallocates the
		 * node buffers and silently detaches a memory-mapped view. */
		if (usearch_reject_if_read_only(intern) == FAILURE) {
			return;
		}
		if (th < 0) {
			zend_throw_error(zend_ce_value_error, "threads must be non-negative");
			return;
		}
		usearch_change_threads_search((usearch_index_t)handle, (size_t)th, &error);
		if (usearch_check_error(&error) == FAILURE) {
			return;
		}
		intern->threads_search = th;
	}

	RETURN_LONG(intern->threads_search);
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

	dims = usearch_effective_dims(handle, intern->metric_kind, &error);
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

	/* usearch_distance() does not convert: its scalar_kind argument says what
	 * the buffers already hold, and it builds a metric over exactly that. So
	 * the buffers must be in the index's stored format before the call, which
	 * means the marshalled f32 has to be quantized first. Passing the f32
	 * buffer with the index's scalar kind — or hardcoding f32 as this did —
	 * both misdescribe the memory; f32 is only correct for an f32 index, and
	 * the bit-packed metrics exist solely over b1x8, where upstream then had
	 * no (metric, f32) kernel to call at all.
	 *
	 * For quantized kinds the measurement also goes through the index's own
	 * metric object rather than the standalone builder: upstream's fresh
	 * metric_punned_t routes the bit-packed kernels to NumKong with an
	 * argument the kernel reads as a different unit, and 8-bit Hamming over
	 * (0xFF, 0x00) reports 4.0 while the same metric answering search()
	 * reports 8.0. The index's instance is the working authority. */
	if ((zend_long)intern->scalar_kind == (zend_long)usearch_scalar_f32_k) {
		d = usearch_distance(buf_a, buf_b, usearch_scalar_f32_k, dims,
							 (usearch_metric_kind_t)intern->metric_kind, &error);
	} else {
		size_t bytes = usearch_php_bytes_per_vector((int)intern->scalar_kind, dims);
		unsigned char *packed_a;
		unsigned char *packed_b;
		double dd = 0.0;

		if (bytes == 0) {
			usearch_throw("unsupported quantization for this index");
			if (owned_a)
				efree(buf_a);
			if (owned_b)
				efree(buf_b);
			return;
		}

		packed_a = (unsigned char *)safe_emalloc(bytes, 1, 0);
		packed_b = (unsigned char *)safe_emalloc(bytes, 1, 0);

		if (usearch_php_quantize(buf_a, dims, (int)intern->scalar_kind, packed_a) != 0 ||
			usearch_php_quantize(buf_b, dims, (int)intern->scalar_kind, packed_b) != 0) {
			efree(packed_a);
			efree(packed_b);
			if (owned_a)
				efree(buf_a);
			if (owned_b)
				efree(buf_b);
			usearch_throw("unsupported quantization for this index");
			return;
		}

		if (usearch_php_index_distance(handle, packed_a, packed_b, &dd) != 0) {
			efree(packed_a);
			efree(packed_b);
			if (owned_a)
				efree(buf_a);
			if (owned_b)
				efree(buf_b);
			usearch_throw("index handle is not initialized; construction failed or the index was destroyed");
			return;
		}
		d = (usearch_distance_t)dd;
		efree(packed_a);
		efree(packed_b);
	}

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

/* The method table is hand-maintained integration glue, distinct from the
 * generated signatures in usearch_arginfo.h: usearch.stub.php drives that
 * file through tools/gen_stub.php, but gen_stub emits no method table for a
 * class whose entries live here, so this list has to stay in step by hand.
 * The arginfo-drift CI step diffs the generated signatures; a method added to
 * the stub without one here fails at link time instead.
 *
 * The table sits inside a clang-format off/on pair on purpose. To the
 * formatter the braced initializer is one call, and with `ColumnLimit: 0`
 * every line after the first accumulates a continuation tab: regenerating
 * this table through clang-format produced a 31-level staircase that stayed
 * green under the format gate. Kept flat by hand here; the gate still checks
 * the rest of the file. */

/* clang-format off */
static const zend_function_entry class_Usearch_Index_methods[] = {
	ZEND_ME(Usearch_Index, __construct, arginfo_class_Usearch_Index___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, add, arginfo_class_Usearch_Index_add, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, search, arginfo_class_Usearch_Index_search, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, get, arginfo_class_Usearch_Index_get, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, contains, arginfo_class_Usearch_Index_contains, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, count, arginfo_class_Usearch_Index_count, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, remove, arginfo_class_Usearch_Index_remove, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, rename, arginfo_class_Usearch_Index_rename, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, clear, arginfo_class_Usearch_Index_clear, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, reserve, arginfo_class_Usearch_Index_reserve, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, save, arginfo_class_Usearch_Index_save, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, load, arginfo_class_Usearch_Index_load, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, view, arginfo_class_Usearch_Index_view, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, size, arginfo_class_Usearch_Index_size, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, capacity, arginfo_class_Usearch_Index_capacity, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, dimensions, arginfo_class_Usearch_Index_dimensions, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, connectivity, arginfo_class_Usearch_Index_connectivity, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, expansionAdd, arginfo_class_Usearch_Index_expansionAdd, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, expansionSearch, arginfo_class_Usearch_Index_expansionSearch, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, threadsAdd, arginfo_class_Usearch_Index_threadsAdd, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, threadsSearch, arginfo_class_Usearch_Index_threadsSearch, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, changeMetric, arginfo_class_Usearch_Index_changeMetric, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, memoryUsage, arginfo_class_Usearch_Index_memoryUsage, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, serializedLength, arginfo_class_Usearch_Index_serializedLength, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, hardwareAcceleration, arginfo_class_Usearch_Index_hardwareAcceleration, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, distance, arginfo_class_Usearch_Index_distance, ZEND_ACC_PUBLIC)
	ZEND_ME(Usearch_Index, metadata, arginfo_class_Usearch_Index_metadata, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Usearch_Index, version, arginfo_class_Usearch_Index_version, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Usearch_Index, hardwareAccelerationCompiled, arginfo_class_Usearch_Index_hardwareAccelerationCompiled, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Usearch_Index, hardwareAccelerationAvailable, arginfo_class_Usearch_Index_hardwareAccelerationAvailable, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	PHP_FE_END
};
/* clang-format on */

void usearch_register_index(void)
{
	zend_class_entry ce;
	INIT_CLASS_ENTRY(ce, "Usearch\\Index", class_Usearch_Index_methods);
	usearch_ce_index = zend_register_internal_class(&ce);
	usearch_ce_index->create_object = usearch_index_create_object;
	memcpy(&usearch_index_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
	usearch_index_handlers.offset = XtOffsetOf(usearch_index_object, std);
	usearch_index_handlers.free_obj = usearch_index_free_object;

	/* NULL makes the engine itself refuse `clone $index`; see the note above
	 * usearch_index_require_handle for why a throwing handler is wrong here. */
	usearch_index_handlers.clone_obj = NULL;
}

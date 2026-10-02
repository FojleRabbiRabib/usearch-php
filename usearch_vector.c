/*
 * usearch-php — vector marshalling between PHP values and native float buffers.
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 *
 * Two accepted input shapes, deliberately:
 *   - a PHP array of numbers: converted into a freshly allocated buffer;
 *   - a packed binary string of float32: borrowed directly, the zero-parse path
 *     for high-throughput pipelines.
 *
 * Ownership is explicit through the `owned` out-parameter rather than inferred
 * from the pointer, so a caller can never free a borrowed string buffer.
 *
 * Length mismatches raise ValueError naming both numbers, never a silent
 * truncation or overread. Non-finite components are legal input and pass
 * through unchanged; the safety suite covers them.
 */

#include "usearch_internal.h"

zend_result usearch_vector_in(zval *zv, size_t dimensions, float **out, bool *owned)
{
	*out = NULL;
	*owned = false;

	if (Z_TYPE_P(zv) == IS_STRING) {
		zend_string *s = Z_STR_P(zv);
		size_t expect = dimensions * sizeof(float);
		if (ZSTR_LEN(s) != expect) {
			zend_throw_error(zend_ce_value_error,
							 "packed vector length mismatch: expected %zu bytes for %zu dimensions, got %zu",
							 expect, dimensions, ZSTR_LEN(s));
			return FAILURE;
		}
		/* Borrowed: the caller must not free this. */
		*out = (float *)ZSTR_VAL(s);
		return SUCCESS;
	}

	if (Z_TYPE_P(zv) == IS_ARRAY) {
		HashTable *ht = Z_ARRVAL_P(zv);
		uint32_t count = zend_hash_num_elements(ht);
		zval *val;
		float *buf;
		size_t i = 0;

		/* Dimensions are positional. A keyed array would be mapped in insertion
		 * order, so the same numbers under different keys would silently become
		 * different vectors; reject it instead. */
		if (!zend_array_is_list(ht)) {
			zend_throw_error(zend_ce_value_error,
							 "vector must be a list of floats with consecutive 0-based keys");
			return FAILURE;
		}

		if (count != (uint32_t)dimensions) {
			zend_throw_error(zend_ce_value_error,
							 "vector dimension mismatch: expected %zu, got %u", dimensions, count);
			return FAILURE;
		}

		buf = (float *)safe_emalloc(dimensions, sizeof(float), 0);
		ZEND_HASH_FOREACH_VAL(ht, val)
		{
			ZVAL_DEREF(val);
			if (Z_TYPE_P(val) != IS_LONG && Z_TYPE_P(val) != IS_DOUBLE) {
				zend_throw_error(zend_ce_type_error,
								 "vector component at index %zu must be int or float, %s given",
								 i, zend_zval_type_name(val));
				efree(buf);
				return FAILURE;
			}
			buf[i++] = (float)zval_get_double(val);
		}
		ZEND_HASH_FOREACH_END();

		*out = buf;
		*owned = true;
		return SUCCESS;
	}

	zend_throw_error(zend_ce_type_error,
					 "vector must be an array of floats or a packed binary string, %s given",
					 zend_zval_type_name(zv));
	return FAILURE;
}

void usearch_vector_out(const float *data, size_t dimensions, zval *return_value)
{
	size_t i;
	array_init_size(return_value, (uint32_t)dimensions);
	for (i = 0; i < dimensions; i++) {
		add_next_index_double(return_value, (double)data[i]);
	}
}

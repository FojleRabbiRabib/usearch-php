/*
 * usearch-php — internal shared declarations.
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

#ifndef USEARCH_INTERNAL_H
#define USEARCH_INTERNAL_H

#include "php.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_enum.h"

#include "php_usearch.h"
#include "usearch_bridge.h"

/* Register the Usearch\Metric and Usearch\Scalar int-backed enums. */
void usearch_register_enums(void);

/* Raise Usearch\Exception and return FAILURE. */
zend_result usearch_throw(const char *message);

/* Raise for an object whose handle was never successfully initialized. */
zend_result usearch_throw_no_handle(void);

/* Drain an upstream error slot after a bridge call: non-empty means failure.
 * The slot is owned by the core and only valid until the next call, so it is
 * read and discarded, never stored. */
zend_result usearch_check_error(usearch_error_t *error);

/* Raise when the instance was opened read-only (view) and a mutation is
 * attempted; returns FAILURE so the caller can bail before touching mmap. */
zend_result usearch_reject_if_immutable(usearch_index_object *intern);

/* Marshal a PHP array or packed binary string into a float buffer.
 * Arrays are converted into a freshly allocated buffer (*owned is true,
 * efree(*out) after use); packed strings are borrowed directly (*owned is
 * false, the caller must not free). Returns SUCCESS or raises
 * ValueError/TypeError. */
zend_result usearch_vector_in(zval *zv, size_t dimensions, float **out, bool *owned);

/* Build a PHP array of floats from a native buffer. */
void usearch_vector_out(const float *data, size_t dimensions, zval *return_value);

/* Resolve the enum-or-int argument to a Metric / Scalar case value. */
zend_result usearch_metric_from_zval(zval *zv, zend_long *out);
zend_result usearch_scalar_from_zval(zval *zv, zend_long *out);

/* Haversine is the one metric upstream allows to be created without an explicit
 * `dimensions`, because the width is implied by the coordinates. Every
 * marshalling path needs the width the vectors actually have, not the reported
 * 0, so resolve it here rather than at each call site. */
static zend_always_inline size_t usearch_effective_dims(void *handle, zend_long metric_kind, usearch_error_t *error)
{
	size_t dims = usearch_dimensions((usearch_index_t)handle, error);
	if (dims == 0 && metric_kind == (zend_long)usearch_metric_haversine_k) {
		return 2;
	}
	return dims;
}

#endif /* USEARCH_INTERNAL_H */

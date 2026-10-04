/*
 * usearch-php — the Usearch\Exception class and the raise helpers.
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

#include "usearch_internal.h"

zend_class_entry *usearch_ce_exception;

zend_result usearch_throw(const char *message)
{
	zend_throw_exception(usearch_ce_exception, message != NULL ? message : "unknown usearch error", 0);
	return FAILURE;
}

zend_result usearch_throw_no_handle(void)
{
	return usearch_throw("index handle is not initialized; construction failed or the index was destroyed");
}

zend_result usearch_check_error(usearch_error_t *error)
{
	if (error != NULL && *error != NULL && **error != '\0') {
		const char *text = *error;
		*error = NULL;
		return usearch_throw(text);
	}
	return SUCCESS;
}

zend_result usearch_reject_if_immutable(usearch_index_object *intern)
{
	if (intern->searching) {
		return usearch_throw("cannot mutate the index while a filteredSearch() callback is running");
	}
	if (intern->computing_metric) {
		return usearch_throw("cannot mutate the index while its custom metric callback is running");
	}
	if (intern->read_only) {
		return usearch_throw("cannot modify a memory-mapped read-only index view");
	}
	return SUCCESS;
}

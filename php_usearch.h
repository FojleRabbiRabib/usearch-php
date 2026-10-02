/*
 * usearch-php — module header: identity, version, and the index object struct.
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

#ifndef PHP_USEARCH_H
#define PHP_USEARCH_H

#include "php.h"

extern zend_module_entry usearch_module_entry;
#define phpext_usearch_ptr &usearch_module_entry

/* Packaging version: plain semver, valid in package.xml and version_compare. */
#define PHP_USEARCH_VERSION "0.1.0"
#define PHP_USEARCH_EXTNAME "usearch"

/* The vendored upstream pin, surfaced by Usearch\Index::version(). */
#define PHP_USEARCH_VENDORED "2.26.2"

/* Initial allocation for an index the caller did not pre-size with reserve().
 * Upstream starts at zero capacity and refuses inserts until reserved, so the
 * extension grows geometrically from this floor on the first add(). */
#define USEARCH_MIN_CAPACITY 16

extern zend_class_entry *usearch_ce_index;
extern zend_class_entry *usearch_ce_exception;
extern zend_class_entry *usearch_ce_metric;
extern zend_class_entry *usearch_ce_scalar;

/* The live index handle. usearch_index_t is an opaque void* owned by the core.
 * read_only is set on indexes opened via view() to prevent SIGSEGV on mmap.
 * metric_kind is retained because upstream exposes no getter for it, and
 * distance() needs it to build its metric; an unknown kind would call through
 * a null function pointer. The thread budgets are retained for the same
 * reason: upstream has change_threads_* setters but no getters, so a truthful
 * read-back has to be remembered here. 0 means "automatic". */
typedef struct _usearch_index_object {
	void *handle;
	bool read_only;
	zend_long metric_kind;
	zend_long threads_add;
	zend_long threads_search;
	zend_object std;
} usearch_index_object;

static zend_always_inline usearch_index_object *usearch_index_from_obj(zend_object *obj)
{
	return (usearch_index_object *)((char *)(obj)-XtOffsetOf(usearch_index_object, std));
}

#define Z_USEARCH_INDEX_P(zv) usearch_index_from_obj(Z_OBJ_P((zv)))

#if defined(ZTS) && defined(COMPILE_DL_USEARCH)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif /* PHP_USEARCH_H */

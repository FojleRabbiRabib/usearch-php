/*
 * usearch-php — the Usearch\Metric and Usearch\Scalar int-backed enums.
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 *
 * Both are int-backed enums whose case values are the upstream
 * usearch_metric_*_k / usearch_scalar_*_k constants verbatim, so the .value of
 * a case is meaningful to the core with no translation table.
 */

#include "usearch_internal.h"

zend_class_entry *usearch_ce_metric;
zend_class_entry *usearch_ce_scalar;

static const struct {
	const char *name;
	zend_long value;
} usearch_metric_cases[] = {
	{"Cosine", 1},
	{"Ip", 2},
	{"L2sq", 3},
	{"Haversine", 4},
	{"Divergence", 5},
	{"Pearson", 6},
	{"Jaccard", 7},
	{"Hamming", 8},
	{"Tanimoto", 9},
	{"Sorensen", 10},
};

static const struct {
	const char *name;
	zend_long value;
} usearch_scalar_cases[] = {
	{"F32", 1},
	{"F64", 2},
	{"F16", 3},
	{"I8", 4},
	{"B1", 5},
	{"BF16", 6},
	{"E5M2", 7},
	{"E4M3", 8},
	{"U8", 9},
	{"E2M3", 10},
	{"E3M2", 11},
};

void usearch_register_enums(void)
{
	size_t i;

	usearch_ce_metric = zend_register_internal_enum("Usearch\\Metric", IS_LONG, NULL);
	for (i = 0; i < sizeof(usearch_metric_cases) / sizeof(usearch_metric_cases[0]); i++) {
		zval val;
		ZVAL_LONG(&val, usearch_metric_cases[i].value);
		zend_enum_add_case_cstr(usearch_ce_metric, usearch_metric_cases[i].name, &val);
	}

	usearch_ce_scalar = zend_register_internal_enum("Usearch\\Scalar", IS_LONG, NULL);
	for (i = 0; i < sizeof(usearch_scalar_cases) / sizeof(usearch_scalar_cases[0]); i++) {
		zval val;
		ZVAL_LONG(&val, usearch_scalar_cases[i].value);
		zend_enum_add_case_cstr(usearch_ce_scalar, usearch_scalar_cases[i].name, &val);
	}
}

zend_result usearch_metric_from_zval(zval *zv, zend_long *out)
{
	if (Z_TYPE_P(zv) == IS_LONG) {
		*out = Z_LVAL_P(zv);
		return SUCCESS;
	}
	if (Z_TYPE_P(zv) == IS_OBJECT && instanceof_function(Z_OBJCE_P(zv), usearch_ce_metric)) {
		zval *cval = zend_enum_fetch_case_value(Z_OBJ_P(zv));
		if (cval != NULL && Z_TYPE_P(cval) == IS_LONG) {
			*out = Z_LVAL_P(cval);
			return SUCCESS;
		}
	}
	zend_throw_error(zend_ce_type_error, "metric must be a Usearch\\Metric case or an integer constant");
	return FAILURE;
}

zend_result usearch_scalar_from_zval(zval *zv, zend_long *out)
{
	if (Z_TYPE_P(zv) == IS_LONG) {
		*out = Z_LVAL_P(zv);
		return SUCCESS;
	}
	if (Z_TYPE_P(zv) == IS_OBJECT && instanceof_function(Z_OBJCE_P(zv), usearch_ce_scalar)) {
		zval *cval = zend_enum_fetch_case_value(Z_OBJ_P(zv));
		if (cval != NULL && Z_TYPE_P(cval) == IS_LONG) {
			*out = Z_LVAL_P(cval);
			return SUCCESS;
		}
	}
	zend_throw_error(zend_ce_type_error, "quantization must be a Usearch\\Scalar case or an integer constant");
	return FAILURE;
}

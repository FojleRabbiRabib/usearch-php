/*
 * usearch-php — module entry: MINIT registration and module metadata.
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "Zend/zend_exceptions.h"
#include "ext/spl/spl_exceptions.h"

#include "usearch_internal.h"
#include "usearch_arginfo.h"

void usearch_register_index(void);

PHP_MINIT_FUNCTION(usearch)
{
	(void)type;
	(void)module_number;

	usearch_register_enums();

	{
		zend_class_entry ce;
		INIT_NS_CLASS_ENTRY(ce, "Usearch", "Exception", NULL);
		usearch_ce_exception = zend_register_internal_class_ex(&ce, spl_ce_RuntimeException);
	}

	usearch_register_index();

	return SUCCESS;
}

PHP_MINFO_FUNCTION(usearch)
{
	(void)zend_module;

	php_info_print_table_start();
	php_info_print_table_row(2, "usearch support", "enabled");
	php_info_print_table_row(2, "extension version", PHP_USEARCH_VERSION);
	php_info_print_table_row(2, "vendored USearch", PHP_USEARCH_VENDORED);
	php_info_print_table_row(2, "hardware acceleration (compiled)",
							 usearch_hardware_acceleration_compiled());
	php_info_print_table_row(2, "hardware acceleration (available)",
							 usearch_hardware_acceleration_available());
	php_info_print_table_end();
}

static const zend_function_entry usearch_functions[] = {
	PHP_FE_END};

zend_module_entry usearch_module_entry = {
	STANDARD_MODULE_HEADER,
	PHP_USEARCH_EXTNAME,
	usearch_functions,
	PHP_MINIT(usearch),
	NULL,
	NULL,
	NULL,
	PHP_MINFO(usearch),
	PHP_USEARCH_VERSION,
	STANDARD_MODULE_PROPERTIES};

#ifdef COMPILE_DL_USEARCH
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(usearch)
#endif

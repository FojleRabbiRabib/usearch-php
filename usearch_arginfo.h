/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: aa127e4d7c0eb260c3a57dfab34e050bb00c247a */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Usearch_Index___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_add, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_LONG, 0)
	ZEND_ARG_TYPE_MASK(0, vector, MAY_BE_ARRAY|MAY_BE_STRING, NULL)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_search, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_MASK(0, query, MAY_BE_ARRAY|MAY_BE_STRING, NULL)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, count, IS_LONG, 0, "10")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_get, 0, 1, IS_ARRAY, 1)
	ZEND_ARG_TYPE_INFO(0, key, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_contains, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_count, 0, 1, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_remove arginfo_class_Usearch_Index_count

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_rename, 0, 2, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, from, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, to, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_clear, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_reserve, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, capacity, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_save, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_saveBuffer, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_load arginfo_class_Usearch_Index_save

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_loadBuffer, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, buffer, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_view arginfo_class_Usearch_Index_save

#define arginfo_class_Usearch_Index_viewBuffer arginfo_class_Usearch_Index_loadBuffer

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_size, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_capacity arginfo_class_Usearch_Index_size

#define arginfo_class_Usearch_Index_dimensions arginfo_class_Usearch_Index_size

#define arginfo_class_Usearch_Index_connectivity arginfo_class_Usearch_Index_size

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_expansionAdd, 0, 0, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, expansion, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_expansionSearch arginfo_class_Usearch_Index_expansionAdd

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_threadsAdd, 0, 0, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, threads, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_threadsSearch arginfo_class_Usearch_Index_threadsAdd

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_changeMetric, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, metric, Usearch\\Metric, MAY_BE_LONG, NULL)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_memoryUsage arginfo_class_Usearch_Index_size

#define arginfo_class_Usearch_Index_serializedLength arginfo_class_Usearch_Index_size

#define arginfo_class_Usearch_Index_hardwareAcceleration arginfo_class_Usearch_Index_saveBuffer

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_distance, 0, 2, IS_DOUBLE, 0)
	ZEND_ARG_TYPE_MASK(0, a, MAY_BE_ARRAY|MAY_BE_STRING, NULL)
	ZEND_ARG_TYPE_MASK(0, b, MAY_BE_ARRAY|MAY_BE_STRING, NULL)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_metadata, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_metadataBuffer, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, buffer, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_version arginfo_class_Usearch_Index_saveBuffer

#define arginfo_class_Usearch_Index_hardwareAccelerationCompiled arginfo_class_Usearch_Index_saveBuffer

#define arginfo_class_Usearch_Index_hardwareAccelerationAvailable arginfo_class_Usearch_Index_saveBuffer

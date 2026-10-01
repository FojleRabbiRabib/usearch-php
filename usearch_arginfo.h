/* This is a generated file, edit the .stub.php file instead.
 * Generated for usearch-php public surface.
 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Usearch_Index___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Usearch_Index_add, 0, 0, 2)
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

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_remove, 0, 1, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_LONG, 0)
ZEND_END_ARG_INFO()

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

#define arginfo_class_Usearch_Index_load arginfo_class_Usearch_Index_save
#define arginfo_class_Usearch_Index_view arginfo_class_Usearch_Index_save

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_size, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_capacity arginfo_class_Usearch_Index_size
#define arginfo_class_Usearch_Index_dimensions arginfo_class_Usearch_Index_size
#define arginfo_class_Usearch_Index_connectivity arginfo_class_Usearch_Index_size

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_expansionAdd, 0, 0, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, expansion, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_expansionSearch arginfo_class_Usearch_Index_expansionAdd
#define arginfo_class_Usearch_Index_threadsAdd arginfo_class_Usearch_Index_expansionAdd
#define arginfo_class_Usearch_Index_threadsSearch arginfo_class_Usearch_Index_expansionAdd

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_changeMetric, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_MASK(0, metric, MAY_BE_OBJECT|MAY_BE_LONG, NULL)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_memoryUsage arginfo_class_Usearch_Index_size
#define arginfo_class_Usearch_Index_serializedLength arginfo_class_Usearch_Index_size

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_hardwareAcceleration, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_distance, 0, 2, IS_DOUBLE, 0)
	ZEND_ARG_TYPE_MASK(0, a, MAY_BE_ARRAY|MAY_BE_STRING, NULL)
	ZEND_ARG_TYPE_MASK(0, b, MAY_BE_ARRAY|MAY_BE_STRING, NULL)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Usearch_Index_metadata, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Usearch_Index_version arginfo_class_Usearch_Index_hardwareAcceleration
#define arginfo_class_Usearch_Index_hardwareAccelerationCompiled arginfo_class_Usearch_Index_hardwareAcceleration
#define arginfo_class_Usearch_Index_hardwareAccelerationAvailable arginfo_class_Usearch_Index_hardwareAcceleration

ZEND_METHOD(Usearch_Index, __construct);
ZEND_METHOD(Usearch_Index, add);
ZEND_METHOD(Usearch_Index, search);
ZEND_METHOD(Usearch_Index, get);
ZEND_METHOD(Usearch_Index, contains);
ZEND_METHOD(Usearch_Index, count);
ZEND_METHOD(Usearch_Index, remove);
ZEND_METHOD(Usearch_Index, rename);
ZEND_METHOD(Usearch_Index, clear);
ZEND_METHOD(Usearch_Index, reserve);
ZEND_METHOD(Usearch_Index, save);
ZEND_METHOD(Usearch_Index, load);
ZEND_METHOD(Usearch_Index, view);
ZEND_METHOD(Usearch_Index, size);
ZEND_METHOD(Usearch_Index, capacity);
ZEND_METHOD(Usearch_Index, dimensions);
ZEND_METHOD(Usearch_Index, connectivity);
ZEND_METHOD(Usearch_Index, expansionAdd);
ZEND_METHOD(Usearch_Index, expansionSearch);
ZEND_METHOD(Usearch_Index, threadsAdd);
ZEND_METHOD(Usearch_Index, threadsSearch);
ZEND_METHOD(Usearch_Index, changeMetric);
ZEND_METHOD(Usearch_Index, memoryUsage);
ZEND_METHOD(Usearch_Index, serializedLength);
ZEND_METHOD(Usearch_Index, hardwareAcceleration);
ZEND_METHOD(Usearch_Index, distance);
ZEND_METHOD(Usearch_Index, metadata);
ZEND_METHOD(Usearch_Index, version);
ZEND_METHOD(Usearch_Index, hardwareAccelerationCompiled);
ZEND_METHOD(Usearch_Index, hardwareAccelerationAvailable);

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

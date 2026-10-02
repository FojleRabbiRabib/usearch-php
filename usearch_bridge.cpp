/*
 * usearch-php — C++ implementation bridge wrapping vendored USearch core
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

#include "usearch_bridge.h"

#if defined(__GNUC__) || defined(__clang__)
#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Wunused-parameter"
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"
#endif

// Pull in the C implementation from the pinned vendored USearch tree
#include "c/lib.cpp"

#if defined(__GNUC__) || defined(__clang__)
#pragma GCC diagnostic pop
#endif

// The bridge owns the C-visible surface, so this file is also where the C API's
// missing pieces live. usearch_distance() takes raw buffers and a scalar kind
// and does not convert, so a caller comparing against a quantized index needs
// the f32-to-stored-format conversion the C API never exposes. Both functions
// below dispatch to the core's own casters (cast_gt<> in index_plugins.hpp) —
// the same functions add() and get() use — rather than restating the rules,
// which are not element-wise: the 8-bit formats rescale by vector magnitude.
//
// Every scalar-kind argument arriving from C is a usearch_scalar_kind_t and
// every internal use needs a unum::usearch::scalar_kind_t. The two enums do
// not share numbering — C has b1 = 5 and f32 = 1, C++ has b1x8_k = 1 and
// f32_k = 11 — so the conversion is scalar_kind_to_cpp(), never a cast. A cast
// silently quantized B1 vectors as E5M2, which is a different width entirely.

#include <usearch/index_plugins.hpp>

extern "C" size_t usearch_php_bytes_per_vector(int scalar_kind, size_t dimensions)
{
	scalar_kind_t kind = scalar_kind_to_cpp((usearch_scalar_kind_t)scalar_kind);
	size_t bits = unum::usearch::bits_per_scalar(kind);
	if (bits == 0)
		return 0;
	return (dimensions * bits + (CHAR_BIT - 1)) / CHAR_BIT;
}

extern "C" int usearch_php_quantize(void const *from, size_t dimensions, int scalar_kind, void *to)
{
	byte_t const *in = (byte_t const *)from;
	byte_t *out = (byte_t *)to;
	scalar_kind_t kind = scalar_kind_to_cpp((usearch_scalar_kind_t)scalar_kind);

#define USEARCH_PHP_CAST(target)                                                              \
	do {                                                                                      \
		if (!unum::usearch::cast_gt<unum::usearch::f32_t, target>::try_(in, dimensions, out)) \
			return -1;                                                                        \
		return 0;                                                                             \
	} while (0)

	switch (kind) {
	case scalar_kind_t::f64_k:
		USEARCH_PHP_CAST(unum::usearch::f64_t);
	case scalar_kind_t::f32_k:
		USEARCH_PHP_CAST(unum::usearch::f32_t);
	case scalar_kind_t::bf16_k:
		USEARCH_PHP_CAST(unum::usearch::bf16_t);
	case scalar_kind_t::f16_k:
		USEARCH_PHP_CAST(unum::usearch::f16_t);
	case scalar_kind_t::e5m2_k:
		USEARCH_PHP_CAST(unum::usearch::e5m2_t);
	case scalar_kind_t::e4m3_k:
		USEARCH_PHP_CAST(unum::usearch::e4m3_t);
	case scalar_kind_t::e3m2_k:
		USEARCH_PHP_CAST(unum::usearch::e3m2_t);
	case scalar_kind_t::e2m3_k:
		USEARCH_PHP_CAST(unum::usearch::e2m3_t);
	case scalar_kind_t::i8_k:
		USEARCH_PHP_CAST(unum::usearch::i8_t);
	case scalar_kind_t::u8_k:
		USEARCH_PHP_CAST(unum::usearch::u8_t);
	case scalar_kind_t::b1x8_k:
		USEARCH_PHP_CAST(unum::usearch::b1x8_t);
	default:
		return -1;
	}
#undef USEARCH_PHP_CAST
}

extern "C" int usearch_php_index_distance(usearch_index_t index, void const *a, void const *b, double *out)
{
	index_dense_t *dense = (index_dense_t *)index;
	if (dense == nullptr || a == nullptr || b == nullptr || out == nullptr)
		return -1;

	// The metric the index would itself use to compare two stored vectors. Going
	// through it rather than a freshly constructed metric_punned_t is what keeps
	// the bit-packed kinds correct; see the header for what the standalone call
	// does to them.
	*out = (double)dense->metric()((byte_t const *)a, (byte_t const *)b);
	return 0;
}

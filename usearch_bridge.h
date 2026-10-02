/*
 * usearch-php — C API bridge header
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

#ifndef USEARCH_BRIDGE_H
#define USEARCH_BRIDGE_H

#ifdef __cplusplus
extern "C" {
#endif

#include "usearch.h"

/**
 * Number of bytes a vector of `dimensions` values occupies in the given scalar
 * format. Needed because the formats are not a fixed stride: the bit-packed
 * ones use one bit per dimension, so the caller cannot size a buffer without
 * asking. Returns 0 for a scalar kind upstream has no implementation for.
 */
size_t usearch_php_bytes_per_vector(int scalar_kind, size_t dimensions);

/**
 * Converts `dimensions` f32 values from `from` into `scalar_kind` at `to`,
 * using upstream's own cast rules. `to` must be at least
 * usearch_php_bytes_per_vector(scalar_kind, dimensions) bytes.
 *
 * The cast rules are not trivial — i8 and u8 scale the whole vector by its L2
 * magnitude rather than clamping element-wise — so this defers to the vendored
 * core's casters instead of restating them in C, where they would silently
 * drift from the format the index itself writes.
 *
 * Returns 0 on success, -1 if the scalar kind has no implementation.
 */
int usearch_php_quantize(void const *from, size_t dimensions, int scalar_kind, void *to);

/**
 * Measures the distance between two already-quantized buffers through the
 * metric object the given index itself uses for search — never through a
 * freshly built one. `a` and `b` must be in the index's stored format, sized
 * by usearch_php_bytes_per_vector.
 *
 * The distinction matters for the bit-packed metrics: upstream's standalone
 * usearch_distance() builds a metric_punned_t the same way the index does, but
 * the NumKong kernels it then routes b1x8 kinds to read the argument as a
 * different unit than the router writes it — measured on this machine, an
 * 8-bit Hamming over (0xFF, 0x00) reported 4.0, a single-bit difference
 * reported 0.0, and only the first bit of the buffer influenced the result at
 * all. The very same metric object answering search() answers correctly, so
 * the index's instance is the authority this defers to.
 *
 * Returns 0 on success, -1 if the handle is unusable.
 */
int usearch_php_index_distance(usearch_index_t index, void const *a, void const *b, double *out);

#ifdef __cplusplus
}
#endif

#endif /* USEARCH_BRIDGE_H */

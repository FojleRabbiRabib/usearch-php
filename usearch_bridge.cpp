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

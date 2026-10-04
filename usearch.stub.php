<?php

/**
 * The public API of the usearch extension.
 *
 * The enum case values are the upstream usearch_metric_*_k and
 * usearch_scalar_*_k constants verbatim. The enum *bodies* are registered in C
 * (gen_stub emits no arginfo for enums), so this file stays the single source
 * of truth for the class entries and the method signatures only; the CI
 * arginfo-drift gate regenerates the arginfo bodies from it and fails on any
 * diff.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Usearch
{
    enum Metric: int
    {
        case Cosine = 1;
        case Ip = 2;
        case L2sq = 3;
        case Haversine = 4;
        case Divergence = 5;
        case Pearson = 6;
        case Jaccard = 7;
        case Hamming = 8;
        case Tanimoto = 9;
        case Sorensen = 10;
    }

    enum Scalar: int
    {
        case F32 = 1;
        case F64 = 2;
        case F16 = 3;
        case I8 = 4;
        case B1 = 5;
        case BF16 = 6;
        case E5M2 = 7;
        case E4M3 = 8;
        case U8 = 9;
        case E2M3 = 10;
        case E3M2 = 11;
    }

    /** A USearch failure, carrying the upstream error text verbatim. */
    class Exception extends \RuntimeException
    {
    }

    final class Index
    {
        /**
         * The option keys mirror usearch_init_options_t 1:1; unknown keys and
         * wrongly typed values raise ValueError. Absent keys leave the library
         * default in place rather than forcing a value.
         */
        public function __construct(array $options = [])
        {
        }

        /**
         * Inserts one vector under one key. `multi` permits duplicate keys.
         */
        public function add(int $key, array|string $vector): void
        {
        }

        /**
         * Returns up to `count` nearest neighbours as `['key' => int, 'distance' => float]` rows.
         */
        public function search(array|string $query, int $count = 10): array
        {
        }

        /**
         * Like `search()`, but only keys whose predicate returns truthy are
         * included. The predicate receives the candidate key and runs on the
         * hot path; it must not mutate the index, and a throw inside it aborts
         * the search and propagates.
         */
        public function filteredSearch(array|string $query, callable $filter, int $count = 10): array
        {
        }

        /**
         * Recovers the stored vector for `key`, or null when absent. Quantized
         * indexes return dequantized values, not the originals.
         */
        public function get(int $key): array|null
        {
        }

        /** Whether any vector is stored under this key. */
        public function contains(int $key): bool
        {
        }

        /** How many vectors are stored under this key. */
        public function count(int $key): int
        {
        }

        /** Removes every vector stored under this key; returns how many. */
        public function remove(int $key): int
        {
        }

        /** Re-keys one vector; returns 1 on success, 0 when `from` is absent. */
        public function rename(int $from, int $to): int
        {
        }

        /** Removes every vector, keeping the allocated capacity. */
        public function clear(): void
        {
        }

        /** Pre-allocates for `capacity` vectors to avoid incremental growth. */
        public function reserve(int $capacity): void
        {
        }

        /** Serializes the index to `path`. */
        public function save(string $path): void
        {
        }

        /**
         * Serializes the index and returns exactly the bytes `save()` writes
         * to a file.
         */
        public function saveBuffer(): string
        {
        }

        /** Deserializes an index from `path` into heap memory. */
        public function load(string $path): void
        {
        }

        /**
         * Deserializes an index from an in-memory buffer into heap memory.
         */
        public function loadBuffer(string $buffer): void
        {
        }

        /**
         * Memory-maps an index from `path` read-only: the process shares the OS
         * page cache across workers instead of holding a private heap copy.
         * Mutating methods raise until the instance is destroyed.
         */
        public function view(string $path): void
        {
        }

        /**
         * Memory-maps an in-memory buffer read-only. The view keeps referencing
         * the bytes for its whole lifetime: the object retains its own copy, so
         * the caller may unset theirs.
         */
        public function viewBuffer(string $buffer): void
        {
        }

        public function size(): int
        {
        }

        public function capacity(): int
        {
        }

        public function dimensions(): int
        {
        }

        public function connectivity(): int
        {
        }

        /** Reads or updates the construction expansion factor. */
        public function expansionAdd(?int $expansion = null): int
        {
        }

        /** Reads or updates the search expansion factor. */
        public function expansionSearch(?int $expansion = null): int
        {
        }

        /** Reads or updates the add thread budget; 0 means automatic. */
        public function threadsAdd(?int $threads = null): int
        {
        }

        /** Reads or updates the search thread budget; 0 means automatic. */
        public function threadsSearch(?int $threads = null): int
        {
        }

        /** Retunes the metric space on an existing index. */
        public function changeMetric(Metric|int $metric): void
        {
        }

        /** Bytes of native memory this index holds, outside Zend MM. */
        public function memoryUsage(): int
        {
        }

        /** Exact size in bytes a serialized form of this index will occupy. */
        public function serializedLength(): int
        {
        }

        /** The SIMD dispatch this index instance resolved to. */
        public function hardwareAcceleration(): string
        {
        }

        /** Distance between two vectors under this index's metric. */
        public function distance(array|string $a, array|string $b): float
        {
        }

        /** Reads the option set stored in an index file without loading it. */
        public static function metadata(string $path): array
        {
        }

        /** Reads the option set stored in an in-memory buffer without loading it. */
        public static function metadataBuffer(string $buffer): array
        {
        }

        /** The extension version plus the vendored USearch pin. */
        public static function version(): string
        {
        }

        /** The SIMD instruction set this build was compiled for. */
        public static function hardwareAccelerationCompiled(): string
        {
        }

        /** The best SIMD instruction set this host can execute. */
        public static function hardwareAccelerationAvailable(): string
        {
        }
    }
}

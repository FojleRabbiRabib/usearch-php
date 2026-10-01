# Usage Guide — usearch-php

Complete API reference for the `Usearch\Index` class and its supporting enums.

---

## 1. Quick Start

```php
use Usearch\Index;
use Usearch\Metric;

$index = new Index([
    'dimensions' => 768,
    'metric' => Metric::Cosine,
]);

$index->add(1001, $embedding);          // one vector, one key
$neighbours = $index->search($query, 5);

foreach ($neighbours as $row) {
    printf("key=%d distance=%.4f\n", $row['key'], $row['distance']);
}
```

The extension runs the HNSW graph in-process. There is no daemon, no socket, and no system library
dependency beyond libc.

---

## 2. Constructor Options

`new Index(array $options = [])`. Keys map 1:1 onto USearch's `usearch_init_options_t`. **Absent
keys leave the library default in place**; unknown keys raise `ValueError`.

| Key | Type | Library default | Meaning |
|---|---|---|---|
| `dimensions` | int > 0 | — (**required**) | Vector width. Optional only for the `Haversine` metric. |
| `metric` | `Metric` \| int | `Metric::Cosine` | Distance function. |
| `quantization` | `Scalar` \| int | `Scalar::F32` | Storage precision/compression. |
| `connectivity` | int | 16 | Max graph degree (M). Higher = more accurate, more memory. |
| `expansionAdd` | int | 128 | Candidate list size during construction. |
| `expansionSearch` | int | 64 | Candidate list size during search. |
| `multi` | bool | `false` | Allow multiple vectors per key. |

---

## 3. Metric spaces — `Usearch\Metric`

| Case | Value | Space | Notes |
|---|---|---|---|
| `Cosine` | 1 | Angular | Vectors normalized internally. |
| `Ip` | 2 | Inner product | For pre-normalized maximum-inner-product search. |
| `L2sq` | 3 | Squared Euclidean | Monotonic with L2; cheaper (no sqrt). |
| `Haversine` | 4 | Great-circle | Geo coordinates; `dimensions` may be omitted. |
| `Divergence` | 5 | KL divergence | Probability distributions. |
| `Pearson` | 6 | Correlation | Centered cosine. |
| `Jaccard` | 7 | Set similarity | Binary/uint vectors. |
| `Hamming` | 8 | Bit differences | Binary vectors (`Scalar::B1`). |
| `Tanimoto` | 9 | Generalized Jaccard | Weighted sets. |
| `Sorensen` | 10 | Dice coefficient | Weighted sets. |

## 4. Quantization — `Usearch\Scalar`

| Case | Value | Bytes/dim | Notes |
|---|---|---|---|
| `F32` | 1 | 4 | Full precision; default. |
| `F64` | 2 | 8 | Double precision. |
| `F16` | 3 | 2 | Half precision; recommended default for most workloads. |
| `I8` | 4 | 1 | 8-bit integers; cosine-like metrics only. |
| `B1` | 5 | 1 bit | Binary; binary metrics only (Hamming, Jaccard). |
| `BF16` / `E5M2` / `E4M3` / `E2M3` / `E3M2` | 6–8, 10, 11 | 2 or 1 | Specialized float formats. |
| `U8` | 9 | 1 | Unsigned 8-bit. |

> **Recall caveat:** every quantization except `F32`/`F64` is lossy. `get()` returns *dequantized*
> values, not the original floats. Keep the source vectors elsewhere if you need exact readback.

---

## 5. Methods

### `add(int $key, array|string $vector): void`
Inserts one vector. Returns `void`; failures raise `Usearch\Exception`.

```php
$index->add(42, [0.1, 0.2, 0.3]);            // PHP array
$index->add(43, pack('f3', 0.1, 0.2, 0.3));  // packed float32 — zero-parse path
```

> **Batch ingestion is not atomic.** Upstream has no batch API; passing many vectors means calling
> `add()` in a PHP loop. If element *N* fails, elements 0…*N*−1 remain committed. Build into a fresh
> index and swap, or snapshot with `save()` and restore, when you need all-or-nothing semantics.

### `search(array|string $query, int $count = 10): array`
Returns up to `$count` rows, nearest first:

```php
[ ['key' => 42, 'distance' => 0.013], ['key' => 17, 'distance' => 0.221] ]
```

Approximate by construction — the returned set is the HNSW graph's best answer, not a guaranteed
exact top-k. Raise `expansionSearch` for higher recall at higher cost.

### `get(int $key): ?array`
Returns the stored vector, or `null` when the key is absent or the index is lossy-quantized (values
are dequantized).

### `contains(int $key): bool`
True when at least one vector is stored under `$key`.

### `count(int $key): int`
How many vectors are stored under `$key` (greater than 1 only with `multi => true`).

### `remove(int $key): int`
Removes every vector under `$key`; returns how many were removed.

### `rename(int $from, int $to): int`
Re-keys one vector; returns 1 on success, 0 when `$from` is absent.

### `clear(): void`
Removes every vector, keeping allocated capacity.

### `reserve(int $capacity): void`
Pre-allocates for `$capacity` vectors, avoiding incremental graph growth during bulk load.

### `save(string $path): void` / `load(string $path): void`
Serialize to and deserialize from disk.

```php
$index->save('/var/lib/search/docs.usearch');

$restored = new Index(['dimensions' => 768]);
$restored->load('/var/lib/search/docs.usearch');
```

### `view(string $path): void`
Memory-maps an index file **read-only**. The process shares the OS page cache instead of holding a
private heap copy — 50 php-fpm workers viewing one 2 GB index consume one 2 GB of cache, not 100 GB
of RAM. All mutating methods (`add`, `remove`, `rename`, `clear`, `reserve`, `changeMetric`, and the
setter forms of `expansionAdd`/`expansionSearch`) raise `Usearch\Exception` while the instance is a
view.

### Introspection
`size()`, `capacity()`, `dimensions()`, `connectivity()`, `memoryUsage()`, `serializedLength()`,
`hardwareAcceleration()`.

### Runtime tuning
`expansionAdd(?int $expansion = null)`, `expansionSearch(?int $expansion = null)`,
`threadsAdd(?int $threads = null)`, `threadsSearch(?int $threads = null)` — each **reads** the
current value when called with no argument and **updates** it when given one.

```php
$index->expansionSearch(256);  // raise recall
echo $index->expansionSearch(); // read it back
```

### `changeMetric(Metric|int $metric): void`
Retunes the metric on an existing index.

### `distance(array|string $a, array|string $b): float`
Distance between two vectors under this index's metric.

### `static metadata(string $path): array`
Reads an index file's option set without loading its vectors:

```php
['metric' => 1, 'quantization' => 1, 'dimensions' => 768, ...]
```

### `static version(): string`
Extension version plus the vendored USearch pin, e.g. `0.1.0+usearch.2.26.2`.

### `static hardwareAccelerationCompiled(): string` / `hardwareAccelerationAvailable(): string`
The SIMD instruction set this build targets, and the best one this host can execute.

---

## 6. Failure modes

| Exception | Raised when |
|---|---|
| `ValueError` | Unknown option key; non-positive `dimensions`; vector length ≠ `dimensions`; non-positive `count`; negative `capacity`/`expansion`/`threads`. |
| `TypeError` | A vector component is not int/float; a vector is neither array nor string; `metric`/`quantization` is neither the enum nor an int. |
| `Usearch\Exception` | Upstream failure: corrupt/truncated index file, unreadable path, metric/quantization mismatch, or a mutation attempted on a read-only `view()`. |

---

## 7. Performance notes

- **Prefer packed strings** (`pack('f*', ...)`) on hot paths — they are passed to the native core
  without per-element conversion.
- **The extension does not disable SIMD dispatch.** `hardwareAcceleration()` reports the ISA the
  index actually resolved to; on modern x86-64 that should be an AVX-512 or AVX2 variant, not a
  scalar fallback.
- **Measure real memory with `memory_usage()` and OS RSS**, not `memory_get_usage()`: Zend's tracker
  is blind to the C++ allocator and the index graph.

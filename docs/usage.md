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

The `Value` column is the upstream kind constant, which is what the index file records. The
`Scalars` column lists the quantization formats upstream selects a kernel for: a metric outside
that set has no implementation and the index cannot be used. `Gated` marks the cases with a
value-level assertion in `tests/quality/metrics.php`.

| Case | Value | Distance returned | Scalars | Gated |
|---|---|---|---|---|
| `Cosine` | 1 | `1 − a·b / (‖a‖·‖b‖)` | F32, F64, F16, BF16, E5M2, E4M3, E3M2, E2M3, I8, U8 | yes |
| `Ip` | 2 | `1 − a·b` (note the offset: identical vectors score `1 − ‖a‖²`) | as `Cosine` | yes |
| `L2sq` | 3 | `Σ(aᵢ − bᵢ)²` | as `Cosine` | yes |
| `Haversine` | 4 | Angular distance in **radians** at unit radius | F32, F64 only | yes |
| `Divergence` | 5 | Jensen–Shannon, ε-smoothed | float kinds only — no I8/U8 | yes |
| `Pearson` | 6 | `1 − r` (centered cosine) | as `Cosine` | yes |
| `Jaccard` | 7 | `1 − \|A ∩ B\| / \|A ∪ B\|` — bound to the **same kernel as `Tanimoto`** | B1 only | yes |
| `Hamming` | 8 | `popcount(A ⊕ B)` | B1 only | yes |
| `Tanimoto` | 9 | `1 − \|A ∩ B\| / \|A ∪ B\|` | B1 only | yes |
| `Sorensen` | 10 | `1 − 2·\|A ∩ B\| / (\|A\| + \|B\|)` | B1 only | yes |

`Jaccard` and `Tanimoto` are not merely similar: upstream dispatches both kind constants to one
function, so on a given index they return identical distances.

The `Value` column is the `Usearch\Metric` case value, which is the **C API's** kind constant. It is
not the internal C++ constant — the two enums do not share numbering (C: `b1 = 5`, `f32 = 1`;
C++: `b1x8_k = 1`, `f32_k = 11`). Nothing in this extension mixes them, but the distinction matters
if you compare values against upstream's headers.

**`B1` is a different kind of input, not a smaller one.** The four binary metrics read bit-packed
words, so `Scalar::B1` is required and a float-only index cannot use them. At `add()` time a
component becomes a set bit when it is **greater than zero** — negatives and `0` clear the bit,
`INF` sets it, and `NAN` clears it (`NAN > 0` is false in IEEE arithmetic, whatever a value's
magnitude suggests). `get()` reads set bits back as `1.0` and unset bits as `0.0`. The original
magnitudes are not recoverable, which is the point of the format.

`distance()` accepts vectors in the array or packed-f32 form **regardless of the index's
quantization**: it quantizes them with the same rules `add()` uses before measuring. On a `B1` index
that means the components are thresholded at zero first, so `distance()` and `search()` always
answer in the same format.

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
Returns the stored vector, or `null` when the key is absent. Quantized indexes return dequantized
values, not the originals.

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
setter forms of `expansionAdd`/`expansionSearch`/`threadsAdd`/`threadsSearch`) raise
`Usearch\Exception` while the instance is a view.

### Introspection
`size()`, `capacity()`, `dimensions()`, `connectivity()`, `memoryUsage()`, `serializedLength()`,
`hardwareAcceleration()`.

### Runtime tuning
`expansionAdd(?int $expansion = null)`, `expansionSearch(?int $expansion = null)`,
`threadsAdd(?int $threads = null)`, `threadsSearch(?int $threads = null)` — each **reads** the
current value when called with no argument and **updates** it when given one. The readback
describes what *this object* set: `load()` and `view()` replace the underlying index with one
whose budgets came from the file (and upstream exposes no getter for them), so both reset the
`threads*` readback to `0` ("unset by this object"). A value read back after `load()` reflects
only a `threads*` call made after the load.

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
- **Measure real memory with `memoryUsage()` and OS RSS**, not `memory_get_usage()`: Zend's tracker
  is blind to the C++ allocator and the index graph.

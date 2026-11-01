# Benchmarks

All numbers in this document were produced by `tools/benchmark.php` on the recorded
machine, and every table names its corpus. Vector-search throughput is
**corpus-dependent** — HNSW build cost varies ~15× between smooth, correlated vectors
and uniform or near-identical ones with no code difference — so a bare "vectors/sec"
without its data is marketing, not measurement. Reproduce with:

```bash
php -n -d extension=modules/usearch.so tools/benchmark.php quick   # or: full
php -n -d extension=modules/usearch.so tools/benchmark-compare.php # vs pure-PHP / external-service baselines
php -n -d extension=modules/usearch.so tools/benchmark.php ratchet # regression gate vs the committed baseline
```

## Machine

- PHP 8.3.35 (CLI, NTS), x86_64 Linux (11th Gen Intel i5-1135G7), glibc
- Host ISA dispatch: serial, haswell, skylake, icelake (AVX-512 enabled; the compiled
  dispatch table also carries genoa, turin, alder and selects at runtime)
- Cosine metric, F32 quantization unless stated; queries single-threaded,
  index builds multi-threaded

## Search throughput

Uniform synthetic corpus (`(k·31 + j·17) mod 1000 / 1000`), k = 10.

| Vectors | Dims | Add rate | Query latency | Queries/sec | Native memory |
|---|---|---|---|---|---|
| 1,000 | 128 | 16,320/s | 30 µs | 33,384 | 16.0 MiB |
| 10,000 | 768 | 932/s | 461 µs | 2,169 | 64.1 MiB |
| 100,000 | 768 | 453/s | 501 µs | 1,995 | 528.8 MiB |

`gen` time (PHP-side vector construction) is excluded from add rates.

## Memory: heap vs memory-mapped

200,000 × 128-dim, serialized index 126.0 MiB on disk.

| Mode | RSS delta | Query latency |
|---|---|---|
| `view()` (memory-mapped) | **39.3 MiB** | 22 µs |
| `load()` (heap copy) | 134.8 MiB | 21 µs |

The mapped view costs **3.4× less process memory** at equal query speed, and the OS
page cache is shared across php-fpm workers — the per-worker delta above is the
private cost.

## Quantization trade-off

100,000 × 768-dim, search k = 10. Measured under Cosine for F32, F16, and I8;
B1 is measured under Hamming because binary quantization requires a binary metric
space (see the matrix in [usage.md](usage.md)).

| Format | Metric | Native memory | Per vector | Query latency |
|---|---|---|---|---|
| F32 | Cosine | 528.8 MiB | 5,544 B | 29 µs |
| F16 | Cosine | 272.8 MiB | 2,860 B | 19 µs |
| I8 | Cosine | 144.8 MiB | 1,518 B | 16 µs |
| B1 | Hamming | 48.8 MiB | 511 B | **6 µs** |

B1 stores one bit per dimension: **11× less memory and 4.8× faster queries** than F32,
at the cost of a binary similarity (a component contributes its sign, not its
magnitude — see the quantization rules in [usage.md](usage.md)).

## Quality (not throughput)

The recall gate (`tests/quality/recall.php`) pins search *quality*: recall@10 over
30,000 Fashion-MNIST vectors (784-dim), threshold 0.95, deterministic single-threaded
build. Measured **0.9990 mean, 0.80 worst** at tag time. Metric *correctness* is a
separate gate (`tests/quality/metrics.php`): 24 value-level assertions against
reference arithmetic, independent of the extension.

## Regression ratchet

`tools/benchmark.php ratchet` measures a fixed deterministic suite — build+search at
5,000×128 and 2,000×768, F16- and I8-quantized search at 5,000×768, and the memory-mapped
`view()` and heap `load()` query paths over a serialized 20,000×128 index — and gates every
metric against the committed baseline `tests/quality/benchmark-baseline.json`:

| Metric | Fails beyond | Measures |
|---|---|---|
| `query_us` | +20% | per-query latency (best of three passes) |
| `add_per_vector_us` | +25% | HNSW build rate |
| `memory_bytes` | +2% | `memoryUsage()` — graph plus reserved tapes; exact for a fixed corpus |
| `serialized_bytes_per_vector` | +2% | quantized footprint (F16 1,684 vs I8 917 B/vector at 768-dim) |

Timings take the best of three passes: a single pass on a shared host occasionally catches
a scheduler hiccup, which is noise, not a regression. The quantize cases ratchet through
the serialized size rather than `memoryUsage()` because reserved tape allocator chunks
dominate `memoryUsage()` at this scale and cannot separate scalar kinds — a broken
scalar-kind mapping shows up as a hard footprint regression instead. Exit `1` on any
regression, exit `2` when the baseline is missing, malformed, or recorded on a different
host class (PHP minor, arch, ISA) — different hardware is a re-record, never a
cross-machine comparison.

```bash
php -n -d extension=modules/usearch.so tools/benchmark.php ratchet           # gate
php -n -d extension=modules/usearch.so tools/benchmark.php ratchet --record  # re-baseline
```

Re-record only after a deliberate improvement or a machine change, never to make a
regression go green; the baseline's git history is the performance record.

## What is not measured here

- aarch64 and musl builds (release artifacts are built and gated for them; the
  numbers above are x86_64/glibc)
- ZTS/thread-safety scaling
- Index build rates on real embedding corpora — the synthetic corpus above is
  reproducible, which is why it is the documented one; add your own corpus before
  quoting build numbers for production planning

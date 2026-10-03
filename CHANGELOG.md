# Changelog

All notable changes to `usearch-php` are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Git tags carry the plain extension version (`v0.1.0`); each release pairs it with
a vendored USearch pin, recorded in the tag message, `php_usearch.h`, and the
release notes. 0.1.0 vendored USearch 2.26.2.

## [Unreleased]

### Changed
- The `phpinfo()`/`php --ri usearch` section now reports the upstream USearch pin, thread safety
  (ZTS/NTS), the shipped feature set, and author credit alongside the version and SIMD dispatch.

## 0.1.0 - 2026-10-03

### Added
- In-process vector similarity search (HNSW) backed by a vendored build of USearch 2.26.2.
- Core `Usearch\Index` surface: construction, `add`, `search`, `get`, `contains`, `count`,
  `remove`, `rename`, `clear`, `reserve`.
- File persistence via `save()`, `load()`, and memory-mapped `view()`.
- Read-only guard on `view()` instances, preventing `SIGSEGV` on `PROT_READ` memory.
- `Usearch\Metric` int-backed enum covering 10 metric spaces (Cosine, Inner Product, L2², Haversine,
  Divergence, Pearson, Jaccard, Hamming, Tanimoto, Sorensen).
- `Usearch\Scalar` int-backed enum covering 11 quantization formats (F32, F64, F16, I8, B1, BF16,
  E5M2, E4M3, U8, E2M3, E3M2).
- Vector marshalling supporting PHP float arrays and packed binary strings (`float32` little-endian)
  with strict dimension bounds checking.
- Static diagnostic helpers: `version()`, `hardwareAccelerationCompiled()`,
  `hardwareAccelerationAvailable()`, and `metadata()`.
- Static `distance()` helper for arbitrary vector pairs under the index metric.
- Introspection accessors: `size()`, `capacity()`, `dimensions()`, `connectivity()`,
  `expansionAdd()`, `expansionSearch()`, `threadsAdd()`, `threadsSearch()`, `memoryUsage()`,
  `serializedLength()`, `hardwareAcceleration()`.
- PIE-compatible `composer.json` manifest and `package.xml` for PECL tooling.
- Full phpt behavioural test suite and Valgrind memory churn test, covering empty indexes,
  oversized `k`, non-finite vector components, multi-key groups, corrupt and truncated index files,
  and the refused `clone`.
- `recall@10` quality gate over Fashion-MNIST (MIT), fetched and SHA-256-verified by
  `tools/fetch-dataset.sh`. Measured 0.9990 mean at 30,000 build vectors.
- Metric-correctness gate (`tests/quality/metrics.php`): `distance()` compared against reference
  arithmetic written independently in PHP for L2², Cosine, Inner Product, Haversine, and Pearson,
  plus an assertion that the `Metric` case values are the upstream kind constants.
- `tools/benchmark.php` and `tools/benchmark-compare.php`, reporting real `VmRSS`/`VmHWM` from
  `/proc/self/status` rather than Zend MM alone.

### Changed
- `usearch_arginfo.h` is now generated from `usearch.stub.php` rather than hand-maintained, and a
  CI step fails on drift. Reflection signatures for `add()`, `threadsAdd()`, `threadsSearch()`, and
  `changeMetric()` were corrected to match the stub.
- `search()` clamps an oversized `count` to the index size instead of attempting an allocation that
  fails uncatchably.

### Fixed
- `distance()` on any quantized index other than `F32` measured the wrong values; on a `B1` index
  with a binary-set metric it returned `NaN` for `Hamming`, `Jaccard`, and `Tanimoto`, and read past
  the end of the vector for `Sorensen`. Two faults compounded: the call hardcoded `F32` as the
  buffer format regardless of the index's quantization, and the C-visible scalar-kind enum was
  being reinterpreted as the internal C++ enum, which does not share its numbering (C `b1 = 5`
  against C++ `b1x8_k = 1`), so `B1` vectors were quantized as a different width entirely. Buffers
  are now quantized through the core's own casters and measured through the index's own metric.
- `clone $index` raised a segmentation fault on destruction; cloning is now refused by the engine
  with `Error: Trying to clone an uncloneable object of class Usearch\Index`.
- `threadsAdd()` / `threadsSearch()` reported the expansion value instead of a thread count, and
  their setters were unguarded on a read-only `view()` index.
- `threadsAdd()` / `threadsSearch()` readback kept values set before a `load()` or `view()` even
  though both replace the handle with one whose budgets came from the file; the readback now
  resets alongside `read_only`.
- `expansionSearch()` lacked the read-only guard `expansionAdd()` applies.
- Haversine indexes created without an explicit `dimensions` were unusable through the marshalling
  layer.
- `load()` / `view()` with a corrupt or truncated file now raise `Usearch\Exception` rather than
  reading past the mapping.

# Changelog

All notable changes to `usearch-php` are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Releases encode the vendored USearch version: `v0.1.0+usearch.2.26.2`.

## [Unreleased]

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
- Full phpt behavioural test suite and Valgrind memory churn test.

# usearch-php

**In-process vector similarity search for PHP** — HNSW approximate nearest-neighbour search,
statically linked into a self-contained extension. No daemon. No socket. No system library.

[![CI](https://github.com/FojleRabbiRabib/usearch-php/actions/workflows/ci.yml/badge.svg)](https://github.com/FojleRabbiRabib/usearch-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777bb4.svg)](https://www.php.net)

```php
use Usearch\Index;
use Usearch\Metric;

$index = new Index(['dimensions' => 768, 'metric' => Metric::Cosine]);

$index->add(1001, $embedding);

foreach ($index->search($query, 5) as $row) {
    printf("key=%d distance=%.4f\n", $row['key'], $row['distance']);
}
```

---

## Why this exists

PHP had **zero** native vector-search extensions. Every embEDDINGS workload had to either run an
external vector database (Qdrant, Milvus, pgvector, Pinecone), use an FFI wrapper that needs
`ffi.enable=true` and a system-installed library, or take a per-call process penalty.

`usearch-php` removes all three. The index lives in the PHP process.

| | External vector DB | FFI wrapper | **usearch-php** |
|---|---|---|---|
| Network hop per query | yes | no | **no** |
| Extra daemon to run | yes | no | **no** |
| `ffi.enable` required | no | **yes** | **no** |
| System library to install | yes | **yes** | **no** |
| Memory-mapped shared reads | varies | no | **yes** |

---

## Benchmarks

Measured on PHP 8.3, x86_64 Linux (AVX2 host), Cosine, F32, single-threaded queries. Full
methodology, corpora, and reproduction steps in [docs/benchmarks.md](docs/benchmarks.md) —
vector-search throughput is corpus-dependent, so every number there names its data.

| Workload | Latency | Throughput | Native memory |
|---|---|---|---|
| Search, 1k × 128-dim | 30 µs/query | 33,400 qps | 16.0 MiB |
| Search, 100k × 768-dim | 501 µs/query | ~2,000 qps | 528.8 MiB |
| Memory-mapped `view()`, 200k × 128-dim | 22 µs/query | — | **39.3 MiB** (vs 134.8 MiB heap-loaded) |
| Quantized search, 100k × 768-dim, B1 | **6 µs/query** | — | **48.8 MiB** (11× less than F32) |
| Recall@10, Fashion-MNIST 30k | — | — | 0.999 mean (threshold 0.95) |

---

## Features

- **The complete USearch surface** — all 40 C API symbols bound: create, add, search, get, contains,
  count, remove, rename, clear, reserve, save, load, read-only memory-mapped `view`, and their
  in-memory buffer twins (`saveBuffer`, `loadBuffer`, `viewBuffer`, `metadataBuffer`).
- **Predicate-filtered search** — `filteredSearch()` returns only the keys your PHP callback
  accepts; a throw inside the callback aborts cleanly and the index stays usable.
- **Custom metrics and exact search** — `changeMetric(callable)` installs a PHP distance over the
  stored-format vectors; `static exactSearch()` computes the true brute-force top-k over any
  dataset, no index required.
- **10 metric spaces** — Cosine, Inner Product, L2², Haversine, Divergence, Pearson, Jaccard,
  Hamming, Tanimoto, Sorensen.
- **11 quantization formats** — F32, F64, F16, BF16, I8, U8, B1, and the E5M2/E4M3/E2M3/E3M2
  float formats.
- **Runtime SIMD dispatch preserved** — AVX-512, AVX2, NEON, and SVE are selected at runtime by
  upstream; the build does not flatten that to a single baseline ISA.
- **Memory-mapped serving** — `view()` shares the OS page cache across php-fpm workers instead of
  giving each worker a private heap copy.
- **Multi-ABI** — PHP 8.3, 8.4, and 8.5.
- **Zero dependencies** — glibc only; the USearch core, NumKong, and StringZilla are vendored,
  SHA-256 verified, and statically linked.

---

## Installation

### PIE (recommended)

[PIE](https://github.com/php/pie) resolves the package and installs the matching
prebuilt binary for your PHP version, falling back to a source build when no
prebuilt asset matches:

```bash
pie install usearch-php/usearch
```

### Prebuilt

Download the assets for your PHP ABI from the
[releases page](https://github.com/FojleRabbiRabib/usearch-php/releases). Each release
carries a PIE archive per ABI and platform
(`php_usearch-<version>_php8.N-<arch>-linux-glibc-nts.zip` wrapping `usearch.so`),
a bare `usearch-php8.N-linux-<arch>.so` for direct download, a provenance record per
build, `SHA256SUMS`, and cosign signature bundles. Verify, then add it to your
`php.ini`:

```bash
sha256sum -c SHA256SUMS
cosign verify-blob --bundle usearch-php8.3-linux-x86_64.so.bundle \
    usearch-php8.3-linux-x86_64.so
```

```ini
extension=/path/to/usearch-php8.3-linux-x86_64.so
```

### From source

```bash
./tools/fetch-usearch.sh
phpize && ./configure --enable-usearch && make -j$(nproc)
sudo make install
echo "extension=usearch.so" | sudo tee /etc/php/8.3/mods-available/usearch.ini
```

See [docs/installation.md](docs/installation.md) for the glibc floor and platform notes.

---

## Documentation

- **[docs/usage.md](docs/usage.md)** — complete API reference, metric spaces, quantization formats,
  failure modes.
- **[docs/installation.md](docs/installation.md)** — PIE, source build, prebuilt binaries.
- **[docs/security.md](docs/security.md)** — threat model and safe handling of untrusted index files.

---

## Requirements

- PHP 8.3, 8.4, or 8.5 (NTS or ZTS)
- 64-bit Linux or macOS
- For source builds: a C++17 compiler

---

## Licence

Apache-2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

This product includes software developed by the USearch project (Unum Cloud), NumKong, and
StringZilla, each under the Apache License 2.0. See [NOTICE](NOTICE) for the full attribution.

# usearch-php

**In-process vector similarity search for PHP** — HNSW approximate nearest-neighbour search,
statically linked into a self-contained extension. No daemon. No socket. No system library.

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

## Features

- **Full USearch core surface** — create, add, search, get, contains, count, remove, rename, clear,
  reserve, save, load, and read-only memory-mapped `view`.
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

```bash
pie install usearch-php/usearch
```

Or from source:

```bash
./tools/fetch-usearch.sh
phpize && ./configure --enable-usearch && make -j$(nproc)
sudo make install
echo "extension=usearch.so" | sudo tee /etc/php/8.3/mods-available/usearch.ini
```

See [docs/installation.md](docs/installation.md) for prebuilt binaries and the glibc floor.

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

# CLAUDE.md — usearch-php

Project instructions for AI assistants working in this repository.

## What this project is

A standalone native PHP extension exposing the USearch vector-search library. PECL/PIE-canonical
layout: extension sources at the repository root, the vendored C++ core compiled through a
repo-owned bridge translation unit.

## Build

```bash
./tools/fetch-usearch.sh                 # fetch + SHA-256 verify the pinned core and submodules
phpize && ./configure --enable-usearch
make -j"$(nproc)"
php tools/test-phpt.php                  # behavioural suite
```

The vendored source in `vendor-src/` is **fetched, never committed** — it is gitignored because it
is reproducible from the pins in `tools/fetch-usearch.sh`.

## Layout

| Path | Role |
|---|---|
| `config.m4` | Toolchain checks, hardening probes, sources, link hardening |
| `usearch_bridge.cpp` | The single C++ TU; includes the pinned `c/lib.cpp` |
| `usearch_index.c` | `Usearch\Index` methods and object lifecycle |
| `usearch_vector.c` | Array/packed-string marshalling |
| `usearch_enums.c` | `Usearch\Metric`, `Usearch\Scalar` |
| `usearch_exception.c` | `Usearch\Exception` and raise helpers |
| `usearch.c` | Module entry |
| `usearch.stub.php` | Public surface; `usearch_arginfo.h` is generated from it |
| `tools/` | Fetch, build, test, and gate drivers |
| `tests/phpt/` | Behavioural suite |
| `tests/memory/churn.php` | Valgrind leak-gate driver |
| `docs/` | Shipped product manuals |

Files in the repository root that are planning records stay **untracked and never committed**.

## Engineering rules

- **PHP 8.3+ only** — no PHP 5/7-era extension patterns.
- **Zero legacy/compat scaffolding** — when something changes, update every caller.
- **Never weaken a gate** to make a failure go green. Every red gate is a real defect.
- **`-Wall -Wextra -Werror`** on our own sources; upstream code compiles behind the bridge's
  diagnostic pragmas.
- **No C++ exception may cross the C boundary.**
- **Symbol isolation is load-bearing** — `usearch.map` must export only `get_module`.
- **Report real memory** (`/proc/self/status` VmRSS), never Zend MM alone, for anything involving the
  native index.
- **Validate the pinned submodules too** — NumKong and StringZilla arrive empty in USearch's release
  tarball and are fetched separately.

## Verification before declaring work done

```bash
php tools/test-phpt.php
valgrind --leak-check=full --error-exitcode=1 php -n -d extension=modules/usearch.so tests/memory/churn.php
clang-format-14 --dry-run --Werror usearch.c usearch_index.c usearch_vector.c usearch_enums.c usearch_exception.c usearch_bridge.cpp php_usearch.h usearch_internal.h usearch_bridge.h
vendor/bin/phpcs && vendor/bin/phpstan analyse --no-progress
```

Plus the ELF assertions: RELRO, BIND_NOW, non-executable stack, no RPATH, and only `get_module`
exported (asserted by `tools/build.sh` and `tools/gate-symbols.sh`).

## Licence and attribution

Apache-2.0. Every source file carries an `SPDX-License-Identifier` header. `NOTICE` credits USearch,
NumKong, and StringZilla; it must be preserved by anyone redistributing this work.

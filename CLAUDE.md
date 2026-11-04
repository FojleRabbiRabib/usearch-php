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
| `tools/el8/`, `tools/build-el8.sh` | AlmaLinux 8 build image and its driver: the glibc release binaries are built and gated here |
| `tools/build-deb.sh`, `tools/deb/` | Wraps the EL8-built module in `php<N>-usearch` (never rebuilds it), lints it, and proves a second assembly is byte-identical |
| `tools/test-deb.sh` | Installs the deb in a clean Debian/Ubuntu container and runs the lifecycle (install, upgrade, `phpdismod`, remove, reinstall, purge); `EXPECT_REFUSE=1` asserts a wrong-PHP package is refused |
| `tools/build-rpm.sh`, `tools/rpm/` | Wraps the EL8-built module in `php-usearch` (Remi base layout) and `php<N>-php-usearch` (Remi versioned layout), never rebuilding it; rpmlint must report a clean run and a second assembly under another umask must be byte-identical |
| `tools/test-rpm.sh` | Installs the rpm in a clean EL or Fedora container with Remi PHP and runs the lifecycle (install, upgrade keeping an edited ini as `.rpmnew`, erase leaving `.rpmsave`, reinstall); `EXPECT_REFUSE=1` asserts a wrong-PHP package is refused on its ABI requirement |
| `tools/check-asset-names.sh`, `tools/test-check-asset-names.sh` | Fails when two release assets (across the deb, rpm, module and zip directories) share a name, case-insensitively; the second script is its unit test |
| `tools/sign-rpm.sh`, `tools/sign/` | Signs the release rpms inside a digest-pinned EL8 image with a key from the environment, only after checking every rpm against the SHA-256 list the build job recorded; `tools/test-sign-rpm.sh` proves it with a throwaway key and verifies on EL8, EL9, EL10 and Fedora |
| `tools/lint-workflow-pins.sh`, `tools/test-lint-workflow-pins.sh` | Fails on any action not pinned to a commit SHA, any image without a digest, and any `id-token: write` outside the `finalize` job; the second script is its unit test |
| `tools/gate-hardening.sh` | RELRO, BIND_NOW, non-executable stack, no RPATH; rerun on the stripped module |
| `tools/gate-linkage.sh` | Asserts C-library-only `NEEDED` entries, no `GLIBCXX`/`CXXABI` references, and the glibc ceiling |
| `tests/phpt/` | Behavioural suite |
| `tests/memory/churn.php` | Valgrind leak-gate driver |
| `tests/quality/recall.php` | recall@10 gate over Fashion-MNIST |
| `tests/quality/metrics.php` | metric values checked against independent PHP arithmetic |
| `tests/quality/interop.php` | Cross-language index-file interop gate against upstream C++/Python |
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
php -n -d extension=modules/usearch.so tests/quality/metrics.php
valgrind --leak-check=full --error-exitcode=1 php -n -d extension=modules/usearch.so tests/memory/churn.php
./tools/fetch-dataset.sh && php -n -d extension=modules/usearch.so tests/quality/recall.php
php -n -d extension=modules/usearch.so tests/quality/interop.php
php -n -d extension=modules/usearch.so tools/benchmark.php ratchet
clang-format-14 --dry-run --Werror usearch.c usearch_index.c usearch_vector.c usearch_enums.c usearch_exception.c usearch_bridge.cpp php_usearch.h usearch_internal.h usearch_bridge.h
vendor/bin/phpcs && vendor/bin/phpstan analyse --no-progress
```

Plus the ELF assertions: RELRO, BIND_NOW, non-executable stack, no RPATH, and only `get_module`
exported (asserted by `tools/build.sh` and `tools/gate-symbols.sh`). The ratchet gate is machine-local
(different hardware is a `--record`, never a cross-machine comparison). The recall gate needs the
Fashion-MNIST dataset, which `tools/fetch-dataset.sh` fetches and SHA-256-verifies into the
gitignored `vendor-data/`; it is never committed.

Changes to the release tooling are verified with `tools/test-lint-workflow-pins.sh`,
`tools/test-check-asset-names.sh`, `tools/lint-workflow-pins.sh .github/workflows/*.yml tools/*/Dockerfile`,
`actionlint`, and, for signing, `tools/test-sign-rpm.sh` (a throwaway key, nothing real); package
changes are verified with `tools/test-deb.sh` and `tools/test-rpm.sh` over the distro matrix.

The release binary for glibc Linux is built by `tools/build-el8.sh <php-version> [out-dir]` (default
`dist/el8`; `make clean` deletes every `*.so` under the tree, so package the artifacts before a local
`tools/build.sh`): the image runs the build, the linkage
gate with a glibc 2.28 ceiling, and the phpt, metric, leak, recall and interop gates on the exact bytes
that ship. The C++ runtime is linked statically through the `.usearch-rt/` shim that `config.m4`
generates (adopted only after a link probe; without static `libstdc++` archives a plain
`./configure` falls back to the dynamic runtime with a warning, but `tools/build.sh` then fails its
linkage gate, so install `libstdc++-static` or the distro equivalent for repository builds). Speed parity between two builds on one machine is judged by
`php -n tools/benchmark.php parity <reference.so> <candidate.so> [rounds]` (per-case, alternating
child processes; exit 0 pass, 1 regression, 2 not comparable), never by the whole-suite ratchet across
different binaries.

## Licence and attribution

Apache-2.0. Every source file carries an `SPDX-License-Identifier` header. `NOTICE` credits USearch,
NumKong, and StringZilla; it must be preserved by anyone redistributing this work.

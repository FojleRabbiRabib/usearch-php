#!/usr/bin/env bash
# tools/el8/build.sh — runs inside the EL8 build image (see tools/build-el8.sh).
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Builds the module with the image's gcc-toolset against the image's Remi PHP, asserts
# the C-library-only linkage with the EL8 glibc ceiling, runs the behavioural, metric,
# leak, recall and interop gates against the freshly built binary, and copies the module
# to /out.
set -euo pipefail

PHP_VER="${PHP_VERSION:?PHP_VERSION must be set}"

# shellcheck disable=SC1090
source "/opt/rh/gcc-toolset-${GCC_TOOLSET:?GCC_TOOLSET must be set}/enable"

cd "$(dirname "$0")/../.."
USEARCH_MAX_GLIBC=2.28 ./tools/build.sh "$PHP_VER"

MODULE="$PWD/modules/usearch.so"

# Strip once, here, so every artifact (PIE zip, bare .so, deb, rpm) carries these exact
# bytes and every gate below runs on them. Distro packaging policy requires it.
strip --strip-unneeded "$MODULE"
./tools/gate-hardening.sh "$MODULE"
./tools/gate-symbols.sh "$MODULE"
./tools/gate-linkage.sh "$MODULE" 2.28

tools/test-phpt.php php
php -n -d extension="$MODULE" tests/quality/metrics.php
valgrind --leak-check=full --error-exitcode=1 php -n -d extension="$MODULE" tests/memory/churn.php
php -n -d extension="$MODULE" tests/quality/recall.php
php -n -d extension="$MODULE" tests/quality/interop.php

OUT="/out/usearch-php${PHP_VER}-el8-$(uname -m)"
install -m 0755 "$MODULE" "$OUT.so"
php -i | grep -oE '^PHP Extension => [0-9]+' | grep -oE '[0-9]+$' > "$OUT.phpapi"
[ -s "$OUT.phpapi" ] || { echo "FAIL: could not read the PHP extension API number" >&2; exit 1; }
echo "EL8 BUILD COMPLETED (PHP $PHP_VER, $(g++ -dumpfullversion), $(getconf GNU_LIBC_VERSION))"

#!/usr/bin/env bash
# tools/build.sh — canonical build driver for usearch-php.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Configures, compiles, and hardens usearch.so for a specific PHP ABI. Asserts
# that the resulting shared library meets all ELF hardening requirements and
# that the symbol table exports only get_module.
#
# Usage:
#   tools/build.sh [php-version] [build-type]
#
#   php-version: 8.3 (default), 8.4, or 8.5
#   build-type:  release (default) or debug (unstripped, -O0)
set -euo pipefail

PHP_VER="${1:-8.3}"
BUILD_TYPE="${2:-release}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP_CONFIG="php-config${PHP_VER}"
PHPIZE="phpize${PHP_VER}"

# Prefer the version-suffixed toolchain, but fall back to the unversioned pair
# *after confirming it reports the version we were asked for*. GitHub's
# setup-php installs exactly one PHP per job and exposes it under the plain
# names, so a matrix leg asking for 8.4 on a runner that only has `php-config`
# must still be able to build; the version check is what keeps that fallback
# honest rather than silently building the wrong ABI.
if ! command -v "$PHP_CONFIG" >/dev/null 2>&1; then
	if command -v php-config >/dev/null 2>&1 \
		&& [ "$(php-config --version 2>/dev/null | cut -d. -f1,2)" = "$PHP_VER" ]; then
		PHP_CONFIG="php-config"
		PHPIZE="phpize"
	else
		echo "FAIL: php-config${PHP_VER} not found, and the unversioned php-config does not report $PHP_VER" >&2
		exit 1
	fi
fi

# 1. Fetch and verify pinned source if absent
./tools/fetch-usearch.sh >/dev/null

# 2. Clean previous build state if re-configuring
if [ -f Makefile ]; then
	make distclean >/dev/null 2>&1 || true
fi
"$PHPIZE" --clean >/dev/null 2>&1 || true
"$PHPIZE"

# 3. Configure
CONF_FLAGS="--enable-usearch --with-php-config=$(command -v "$PHP_CONFIG")"
if [ "$BUILD_TYPE" = "debug" ]; then
	./configure $CONF_FLAGS CFLAGS="-g -O0 -Wall -Wextra -Werror" CXXFLAGS="-g -O0 -Wall -Wextra"
else
	./configure $CONF_FLAGS
fi

# 4. Compile
make -j"$(nproc)"

MODULE="$ROOT/modules/usearch.so"
if [ ! -f "$MODULE" ]; then
	echo "FAIL: build did not produce $MODULE" >&2
	exit 1
fi

# 5. ELF hardening assertions (Linux only)
if [ "$(uname -s)" = "Linux" ]; then
	echo "Asserting ELF hardening..."
	readelf -lW "$MODULE" | grep -q "GNU_RELRO" || { echo "FAIL: missing GNU_RELRO"; exit 1; }
	readelf -lW "$MODULE" | grep -E "GNU_STACK.*RWE" && { echo "FAIL: stack is executable"; exit 1; } || true
	readelf -dW "$MODULE" | grep -q "BIND_NOW" || { echo "FAIL: missing BIND_NOW"; exit 1; }
	readelf -dW "$MODULE" | grep -E "RPATH|RUNPATH" && { echo "FAIL: carries RPATH/RUNPATH"; exit 1; } || true

	# Symbol isolation
	LEAKS="$(nm -D --defined-only "$MODULE" | grep -v ' get_module$' || true)"
	if [ -n "$LEAKS" ]; then
		echo "FAIL: symbol table exports symbols beyond get_module:" >&2
		echo "$LEAKS" >&2
		exit 1
	fi
	echo "ELF hardening assertions: PASS (RELRO, BIND_NOW, noexecstack, symbol isolation)"
fi

echo "SUCCESS: usearch.so built for PHP $PHP_VER ($BUILD_TYPE)"

#!/usr/bin/env bash
# tools/build-rpm.sh — package the EL8-built module as rpms in both Remi layouts.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Stage 1 is tools/build-el8.sh (build and gate the module on EL8). Stage 2, this script,
# wraps that exact module in php-usearch (Remi base layout) and php<N>-php-usearch (Remi
# versioned layout) inside the pinned EL8 image and lints them; the binary is never
# rebuilt for the rpm. Signing happens later, in the isolated signing job.
#
# Usage: tools/build-rpm.sh [php-version] [el8-dir] [out-dir]
#   php-version: 8.3 (default), 8.4 or 8.5
#   el8-dir:     where tools/build-el8.sh wrote usearch-php<ver>-el8-<arch>.so (default: dist/el8)
#   out-dir:     where the rpms land (default: dist/rpm)
# Env: KEEP_IMAGE=1 keeps the image after the build.
#
# The version is the PHP_USEARCH_VERSION header. A final 0.3.0 is Version 0.3.0, Release 1;
# a prerelease 0.3.0-rc1 is Version 0.3.0, Release 0.1.rc1, so it sorts below its final (an
# rpm Version cannot contain a hyphen). SOURCE_DATE_EPOCH is the HEAD commit time unless set.
set -euo pipefail

PHP_VER="${1:-8.3}"
EL8_DIR="${2:-dist/el8}"
OUT_DIR="${3:-dist/rpm}"

case "$PHP_VER" in
	8.3) EXPECTED_API=20230831 ;;
	8.4) EXPECTED_API=20240924 ;;
	8.5) EXPECTED_API=20250925 ;;
	*) echo "FAIL: unsupported PHP version '$PHP_VER' (supported: 8.3, 8.4, 8.5)" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

case "$(uname -m)" in
	x86_64) MACHINE=x86_64 ;;
	aarch64|arm64) MACHINE=aarch64 ;;
	*) echo "FAIL: unsupported architecture $(uname -m)" >&2; exit 2 ;;
esac

MODULE="$EL8_DIR/usearch-php${PHP_VER}-el8-${MACHINE}.so"
if [ ! -f "$MODULE" ] || [ ! -s "${MODULE%.so}.phpapi" ]; then
	echo "FAIL: $MODULE (and its .phpapi) not found; run tools/build-el8.sh $PHP_VER first" >&2
	exit 1
fi
PHP_API="$(cat "${MODULE%.so}.phpapi")"
if [ "$PHP_API" != "$EXPECTED_API" ]; then
	echo "FAIL: ${MODULE%.so}.phpapi says '$PHP_API', PHP $PHP_VER has API $EXPECTED_API; stale or misplaced module" >&2
	exit 1
fi

# The package bytes depend on these files, so the "same inputs, same bytes" claim holds
# only when they match the commit whose time is used below.
untracked="$(git ls-files --others --exclude-standard -- tools/rpm)"
if ! git diff --quiet HEAD -- LICENSE NOTICE CHANGELOG.md php_usearch.h tools/rpm || [ -n "$untracked" ]; then
	echo "FAIL: LICENSE, NOTICE, CHANGELOG.md, php_usearch.h or tools/rpm differ from HEAD (or hold untracked files); commit them first" >&2
	exit 1
fi

HEADER_VERSION="$(awk -F'"' '/#define PHP_USEARCH_VERSION/ { print $2; exit }' php_usearch.h)"
[ -n "$HEADER_VERSION" ] || { echo "FAIL: PHP_USEARCH_VERSION not found in php_usearch.h" >&2; exit 1; }
# One optional prerelease suffix; an rpm Release cannot carry a second hyphen.
if ! [[ "$HEADER_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.]+)?$ ]]; then
	echo "FAIL: PHP_USEARCH_VERSION '$HEADER_VERSION' is not X.Y.Z or X.Y.Z-prerelease" >&2
	exit 1
fi
PKG_VERSION="${HEADER_VERSION%%-*}"
if [ "$PKG_VERSION" = "$HEADER_VERSION" ]; then
	PKG_RELEASE=1
else
	PKG_RELEASE="0.1.${HEADER_VERSION#*-}"
fi

SDE="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

mkdir -p "$OUT_DIR"
OUT_ABS="$(cd "$OUT_DIR" && pwd)"

# Only the install test sets this: it builds the same module at a higher version, with a
# marker line in the ini, so a real package upgrade can be exercised. Such a package must
# never sit among release assets, so the default release directory is refused.
TEST_BUILD=0
if [ -n "${PKG_VERSION_OVERRIDE:-}" ]; then
	if [ "$OUT_ABS" = "$ROOT/dist/rpm" ]; then
		echo "FAIL: PKG_VERSION_OVERRIDE builds a test-only package; give it a scratch out-dir, not dist/rpm" >&2
		exit 2
	fi
	PKG_VERSION="$PKG_VERSION_OVERRIDE"
	TEST_BUILD=1
fi

MODULE_ABS="$(cd "$(dirname "$MODULE")" && pwd)/$(basename "$MODULE")"
IMAGE="usearch-rpm:${PHP_VER}-$$"

docker build -q -t "$IMAGE" tools/rpm >/dev/null
cleanup() { [ "${KEEP_IMAGE:-0}" = "1" ] || docker rmi -f "$IMAGE" >/dev/null 2>&1 || true; }
trap cleanup EXIT

status=0
docker run --rm \
	-e "PHP_VERSION=${PHP_VER}" -e "PKG_VERSION=${PKG_VERSION}" -e "PKG_RELEASE=${PKG_RELEASE}" \
	-e "PHP_API=${PHP_API}" -e "SOURCE_DATE_EPOCH=${SDE}" -e "PKG_TEST_BUILD=${TEST_BUILD}" \
	-v "${ROOT}:/src:ro" -v "${MODULE_ABS}:/in/module.so:ro" -v "${OUT_ABS}:/out" \
	--user "$(id -u):$(id -g)" -e HOME=/tmp \
	"$IMAGE" bash /src/tools/rpm/build.sh || status=$?

exit "$status"

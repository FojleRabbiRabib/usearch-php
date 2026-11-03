#!/usr/bin/env bash
# tools/build-deb.sh — package the EL8-built module as a .deb.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Stage 1 is tools/build-el8.sh (build and gate the module on EL8). Stage 2, this script,
# wraps that exact module in a Debian package inside the pinned Debian image and lints
# it; the binary is never rebuilt for the deb.
#
# Usage: tools/build-deb.sh [php-version] [el8-dir] [out-dir]
#   php-version: 8.3 (default), 8.4 or 8.5
#   el8-dir:     where tools/build-el8.sh wrote usearch-php<ver>-el8-<arch>.so (default: dist/el8)
#   out-dir:     where the .deb lands (default: dist/deb)
# Env: KEEP_IMAGE=1 keeps the image after the build.
#
# The package version is the PHP_USEARCH_VERSION header with the pkgrel fixed at 1; a
# prerelease suffix sorts below its final (0.3.0-rc1 becomes 0.3.0~rc1-1). SOURCE_DATE_EPOCH
# is the HEAD commit time unless set, so equal inputs give byte-identical packages.
set -euo pipefail

PHP_VER="${1:-8.3}"
EL8_DIR="${2:-dist/el8}"
OUT_DIR="${3:-dist/deb}"

case "$PHP_VER" in
	8.3|8.4|8.5) ;;
	*) echo "FAIL: unsupported PHP version '$PHP_VER' (supported: 8.3, 8.4, 8.5)" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

case "$(uname -m)" in
	x86_64) MACHINE=x86_64; DEB_ARCH=amd64 ;;
	aarch64|arm64) MACHINE=aarch64; DEB_ARCH=arm64 ;;
	*) echo "FAIL: unsupported architecture $(uname -m)" >&2; exit 2 ;;
esac

MODULE="$EL8_DIR/usearch-php${PHP_VER}-el8-${MACHINE}.so"
if [ ! -f "$MODULE" ] || [ ! -s "${MODULE%.so}.phpapi" ]; then
	echo "FAIL: $MODULE (and its .phpapi) not found; run tools/build-el8.sh $PHP_VER first" >&2
	exit 1
fi
PHP_API="$(cat "${MODULE%.so}.phpapi")"
case "$PHP_VER" in
	8.3) EXPECTED_API=20230831 ;;
	8.4) EXPECTED_API=20240924 ;;
	8.5) EXPECTED_API=20250925 ;;
esac
if [ "$PHP_API" != "$EXPECTED_API" ]; then
	echo "FAIL: ${MODULE%.so}.phpapi says '$PHP_API', PHP $PHP_VER has API $EXPECTED_API; stale or misplaced module" >&2
	exit 1
fi

# The package bytes depend on these files, so the "same inputs, same bytes" claim holds
# only when they match the commit whose time is used below.
untracked="$(git ls-files --others --exclude-standard -- tools/deb)"
if ! git diff --quiet HEAD -- LICENSE NOTICE CHANGELOG.md php_usearch.h tools/deb || [ -n "$untracked" ]; then
	echo "FAIL: LICENSE, NOTICE, CHANGELOG.md, php_usearch.h or tools/deb differ from HEAD (or hold untracked files); commit them first" >&2
	exit 1
fi

HEADER_VERSION="$(awk -F'"' '/#define PHP_USEARCH_VERSION/ { print $2; exit }' php_usearch.h)"
[ -n "$HEADER_VERSION" ] || { echo "FAIL: PHP_USEARCH_VERSION not found in php_usearch.h" >&2; exit 1; }
DEB_VERSION="${HEADER_VERSION/-/\~}-1"

SDE="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

mkdir -p "$OUT_DIR"
OUT_ABS="$(cd "$OUT_DIR" && pwd)"
MODULE_ABS="$(cd "$(dirname "$MODULE")" && pwd)/$(basename "$MODULE")"
IMAGE="usearch-deb:${PHP_VER}-$$"

docker build -q -t "$IMAGE" tools/deb >/dev/null
cleanup() { [ "${KEEP_IMAGE:-0}" = "1" ] || docker rmi -f "$IMAGE" >/dev/null 2>&1 || true; }
trap cleanup EXIT

status=0
docker run --rm \
	-e "PHP_VERSION=${PHP_VER}" -e "DEB_VERSION=${DEB_VERSION}" -e "DEB_ARCH=${DEB_ARCH}" \
	-e "PHP_API=${PHP_API}" -e "SOURCE_DATE_EPOCH=${SDE}" \
	-v "${ROOT}:/src:ro" -v "${MODULE_ABS}:/in/module.so:ro" -v "${OUT_ABS}:/out" \
	--user "$(id -u):$(id -g)" -e HOME=/tmp \
	"$IMAGE" bash /src/tools/deb/build.sh || status=$?

exit "$status"

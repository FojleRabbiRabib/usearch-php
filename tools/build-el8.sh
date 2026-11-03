#!/usr/bin/env bash
# tools/build-el8.sh — build the release module in the EL8 image.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# The release binary is built on EL8 (glibc 2.28) so one module runs on every
# supported distro; see tools/el8/Dockerfile. The source is streamed into the
# container, so nothing is written to this checkout and no file is root-owned.
#
# Usage: tools/build-el8.sh [php-version] [out-dir]
#   php-version: 8.3 (default), 8.4 or 8.5
#   out-dir:     where usearch-php<ver>-el8-<arch>.so lands (default: dist/el8). `make clean`, run by
#                tools/build.sh, deletes every *.so under the tree, so package the artifacts before
#                running a local build, or pick an out-dir outside the checkout.
# Env: GCC_TOOLSET (default 14), KEEP_IMAGE=1 keeps the image after the build.
set -euo pipefail

PHP_VER="${1:-8.3}"
OUT_DIR="${2:-dist/el8}"
GCC_TOOLSET="${GCC_TOOLSET:-14}"

case "$PHP_VER" in
	8.3|8.4|8.5) ;;
	*) echo "FAIL: unsupported PHP version '$PHP_VER' (supported: 8.3, 8.4, 8.5)" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
mkdir -p "$OUT_DIR"
OUT_ABS="$(cd "$OUT_DIR" && pwd)"
IMAGE="usearch-el8:${PHP_VER}"

docker build -q -t "$IMAGE" \
	--build-arg "PHP_VERSION=${PHP_VER}" --build-arg "GCC_TOOLSET=${GCC_TOOLSET}" tools/el8 >/dev/null

# The pinned sources and the recall dataset are fetched and SHA-256 verified on the
# host (both scripts are idempotent), so the container needs no network access of its own.
./tools/fetch-usearch.sh >/dev/null
./tools/fetch-dataset.sh >/dev/null

# Tracked files, plus new untracked ones below the root (so an uncommitted tools/ or tests/
# file is built; untracked root files are planning records and never enter the build), then
# the fetched trees. A file deleted from the working tree is skipped, not a failed archive.
status=0
{
	git ls-files -z --cached
	git ls-files -z --others --exclude-standard | { grep -zE '/' || true; }
	printf 'vendor-src\0vendor-data\0'
} | tar --null --ignore-failed-read --exclude='*.o' --exclude='*.lo' --exclude='*.dep' --exclude='.libs' -T - -cf - | docker run --rm -i \
	-e "PHP_VERSION=${PHP_VER}" \
	-v "${OUT_ABS}:/out" \
	--user "$(id -u):$(id -g)" -e HOME=/tmp \
	"$IMAGE" bash -c 'mkdir -p /tmp/src && tar -xf - -C /tmp/src && exec bash /tmp/src/tools/el8/build.sh' \
	|| status=$?

[ "${KEEP_IMAGE:-0}" = "1" ] || docker rmi -f "$IMAGE" >/dev/null 2>&1 || true
exit "$status"

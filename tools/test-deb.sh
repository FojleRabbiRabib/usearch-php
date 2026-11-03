#!/usr/bin/env bash
# tools/test-deb.sh — install the .deb into a clean distro container and exercise it.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Usage: tools/test-deb.sh <deb-file> [php-version] [image] [provider]
#   php-version: the PHP the container gets (default 8.3)
#   image:       a Debian or Ubuntu image (default ubuntu:24.04)
#   provider:    where that PHP comes from: distro (the image's own archive, default),
#                sury (packages.sury.org, Debian) or ondrej (ppa:ondrej/php, Ubuntu)
# Env: EXPECT_REFUSE=1 asserts apt refuses the package (a package built for another PHP);
#      MODULE_SHA256 asserts the packaged module equals the EL8-built one;
#      TEST_TIMEOUT seconds before the container is killed (default 900).
#
# The package is installed with apt, so its dependencies are resolved for real; the
# lifecycle covers install, a real upgrade, an administrator's phpdismod, remove, reinstall
# and purge.
set -euo pipefail

DEB="${1:?usage: tools/test-deb.sh <deb-file> [php-version] [image] [provider]}"
PHP_VER="${2:-8.3}"
IMAGE="${3:-ubuntu:24.04}"
PROVIDER="${4:-distro}"
NAME="usearch-deb-test-$$"
TIMEOUT="${TEST_TIMEOUT:-900}"

[ -f "$DEB" ] || { echo "FAIL: $DEB not found" >&2; exit 1; }
DEB_ABS="$(cd "$(dirname "$DEB")" && pwd)/$(basename "$DEB")"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

WATCHDOG=""
cleanup() {
	if [ -n "$WATCHDOG" ]; then
		pkill -P "$WATCHDOG" 2>/dev/null || true
		kill "$WATCHDOG" 2>/dev/null || true
		wait "$WATCHDOG" 2>/dev/null || true
	fi
	docker kill "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

( sleep "$TIMEOUT"; echo "TIMED OUT after ${TIMEOUT}s" >&2; docker kill "$NAME" >/dev/null 2>&1 ) </dev/null &
WATCHDOG=$!

status=0
docker run --name "$NAME" --rm \
	-e "PHP_VER=$PHP_VER" -e "PROVIDER=$PROVIDER" -e "EXPECT_REFUSE=${EXPECT_REFUSE:-0}" \
	-e "MODULE_SHA256=${MODULE_SHA256:-}" \
	-v "$DEB_ABS:/pkg.deb:ro" -v "$ROOT/tools/deb/test.sh:/test.sh:ro" \
	"$IMAGE" bash /test.sh || status=$?

if [ "$status" -ne 0 ]; then
	echo "DEB TEST FAILED (exit $status; 137 means the container was killed)" >&2
	exit "$status"
fi
echo "DEB TEST PASSED ($(basename "$DEB") on $IMAGE, PHP $PHP_VER from $PROVIDER)"

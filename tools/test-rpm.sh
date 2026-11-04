#!/usr/bin/env bash
# tools/test-rpm.sh — install the .rpm into a clean EL or Fedora container and exercise it.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Usage: tools/test-rpm.sh <rpm-file> <layout> [php-version] [image] [newer-rpm]
#   layout:      base (php-usearch) or versioned (php<N>-php-usearch)
#   php-version: the PHP the container gets (default 8.3)
#   image:       an EL or Fedora image (default almalinux:8)
#   newer-rpm:   the same module at a higher version, for the upgrade step; build it with
#                PKG_VERSION_OVERRIDE into a scratch out-dir (see tools/build-rpm.sh); its ini
#                differs, so the upgrade step can tell noreplace from a plain config. Not
#                needed with EXPECT_REFUSE=1.
# Env: EXPECT_REFUSE=1 asserts dnf refuses the package (one built for another PHP);
#      MODULE_SHA256 asserts the packaged module equals the EL8-built one;
#      TEST_TIMEOUT seconds before the container is killed (default 900).
set -euo pipefail

RPM="${1:?usage: tools/test-rpm.sh <rpm-file> <layout> [php-version] [image] [newer-rpm]}"
LAYOUT="${2:?layout (base | versioned) is required}"
PHP_VER="${3:-8.3}"
IMAGE="${4:-almalinux:8}"
NEWER="${5:-$RPM}"
NAME="usearch-rpm-test-$$"
TIMEOUT="${TEST_TIMEOUT:-900}"

case "$LAYOUT" in base|versioned) ;; *) echo "FAIL: layout must be base or versioned" >&2; exit 2 ;; esac
[ -f "$RPM" ] || { echo "FAIL: $RPM not found" >&2; exit 1; }
[ -f "$NEWER" ] || { echo "FAIL: $NEWER not found" >&2; exit 1; }
RPM_ABS="$(cd "$(dirname "$RPM")" && pwd)/$(basename "$RPM")"
NEWER_ABS="$(cd "$(dirname "$NEWER")" && pwd)/$(basename "$NEWER")"
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
	-e "PHP_VER=$PHP_VER" -e "LAYOUT=$LAYOUT" -e "EXPECT_REFUSE=${EXPECT_REFUSE:-0}" \
	-e "MODULE_SHA256=${MODULE_SHA256:-}" \
	-v "$RPM_ABS:/pkg.rpm:ro" -v "$NEWER_ABS:/newer.rpm:ro" -v "$ROOT/tools/rpm/test.sh:/test.sh:ro" \
	"$IMAGE" bash /test.sh || status=$?

if [ "$status" -ne 0 ]; then
	echo "RPM TEST FAILED (exit $status; 137 means the container was killed)" >&2
	exit "$status"
fi
echo "RPM TEST PASSED ($(basename "$RPM"), $LAYOUT layout, on $IMAGE, PHP $PHP_VER)"

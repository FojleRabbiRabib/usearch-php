#!/usr/bin/env bash
# tools/test-check-asset-names.sh — unit test for tools/check-asset-names.sh.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CHECK="$ROOT/tools/check-asset-names.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work"/{a,b,c}

failures=0
expect() { # expected-exit label dir...
	local want="$1" label="$2" got=0
	shift 2
	"$CHECK" "$@" >/dev/null 2>&1 || got=$?
	if [ "$got" -eq "$want" ]; then
		printf '  %-58s PASS\n' "$label"
	else
		printf '  %-58s FAIL (exit %s, wanted %s)\n' "$label" "$got" "$want"
		failures=$((failures + 1))
	fi
}

: > "$work/a/php-usearch-1.0.0-1.php83.x86_64.rpm"
: > "$work/b/php8.3-usearch_1.0.0-1_amd64.deb"
: > "$work/c/usearch-php8.3-el8-x86_64.so"
: > "$work/c/usearch-php8.3-el8-x86_64.phpapi"
expect 0 "distinct names across directories pass" "$work/a" "$work/b" "$work/c"

: > "$work/b/PHP-USEARCH-1.0.0-1.PHP83.X86_64.RPM"
expect 1 "names that differ only in case collide" "$work/a" "$work/b"

rm "$work/b/PHP-USEARCH-1.0.0-1.PHP83.X86_64.RPM"
: > "$work/b/php-usearch-1.0.0-1.php83.x86_64.rpm"
expect 1 "the same name in two directories collides" "$work/a" "$work/b"

mkdir -p "$work/d"
: > "$work/d/usearch-php8.3-el8-x86_64.phpapi"
expect 0 "a .phpapi file is not an asset" "$work/c" "$work/d"

mkdir -p "$work/e" "$work/f"
: > "$work/e/SHA256SUMS"
: > "$work/f/SHA256SUMS"
: > "$work/e/one.zip"
: > "$work/f/two.zip"
expect 0 "per-cell SHA256SUMS files are not assets" "$work/e" "$work/f"

mkdir -p "$work/empty"
expect 1 "no files at all is an error" "$work/empty"
expect 1 "a missing directory is an error" "$work/missing"
expect 2 "no arguments is a usage error"

if [ "$failures" -ne 0 ]; then
	echo "ASSET NAME TEST FAILED ($failures)" >&2
	exit 1
fi
echo "ASSET NAME TEST PASSED"

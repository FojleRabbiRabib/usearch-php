#!/usr/bin/env bash
# tools/fetch-usearch.sh — the single fetch+verify path for the pinned USearch source.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Downloads the pinned tarballs if absent, verifies their SHA-256, and extracts
# them under vendor-src/. USearch ships its NumKong and StringZilla dependencies
# as git submodules, and GitHub release tarballs carry those directories empty,
# so all three trees are fetched and verified here. On success the verified
# USearch source directory is printed to stdout; every diagnostic goes to
# stderr.
#
# Env: USEARCH_VERSION, USEARCH_SHA256, NUMKONG_VERSION, NUMKONG_SHA256,
#      STRINGZILLA_VERSION, STRINGZILLA_SHA256.
set -euo pipefail

USEARCH_VERSION="${USEARCH_VERSION:-2.26.2}"
USEARCH_SHA256="${USEARCH_SHA256:-11a7eb49b34be0ce2c7a60af3a4f0a435b6c8f70dbcbd35495bbd3913bc8f665}"
NUMKONG_VERSION="${NUMKONG_VERSION:-7.8.1}"
NUMKONG_SHA256="${NUMKONG_SHA256:-cb5814875b2cee6a843fa458cde3f9684d4ed961f52803a25eb79a5f23a25ce4}"
STRINGZILLA_VERSION="${STRINGZILLA_VERSION:-3.10.10}"
STRINGZILLA_SHA256="${STRINGZILLA_SHA256:-7d6098f660395e0b49f4b4a48f41d12a3067981f2cead52aee626bf40912f253}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
V="$ROOT/vendor-src"
SRC="$V/USearch-$USEARCH_VERSION"

# SHA-256 calculator: `sha256sum` on Linux, `shasum -a 256` on macOS / BSD.
calc_sha256() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{print $1}'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{print $1}'
	else
		echo "FAIL: neither sha256sum nor shasum found" >&2
		exit 1
	fi
}

# fetch_verified <tarball> <url> <expected-sha256>
fetch_verified() {
	local tarball="$1" url="$2" want="$3" actual
	if [ ! -f "$tarball" ]; then
		curl -fsSL -o "$tarball" "$url"
	fi
	actual="$(calc_sha256 "$tarball")"
	if [ "$actual" != "$want" ]; then
		echo "FAIL: SHA256 mismatch for $(basename "$tarball") (expected $want, got $actual)" >&2
		rm -f "$tarball"
		exit 1
	fi
}

mkdir -p "$V"

fetch_verified "$V/usearch-v$USEARCH_VERSION.tar.gz" \
	"https://github.com/unum-cloud/usearch/archive/refs/tags/v$USEARCH_VERSION.tar.gz" \
	"$USEARCH_SHA256"

if [ ! -f "$SRC/c/usearch.h" ]; then
	rm -rf "$SRC"
	mkdir -p "$SRC"
	tar xzf "$V/usearch-v$USEARCH_VERSION.tar.gz" -C "$SRC" --strip-components=1
fi

# NumKong and StringZilla arrive empty in the USearch tarball: they are git
# submodules, and neither archive endpoint materialises them. Both are required
# for the C++ core to compile, so fetch them into their submodule paths.
fetch_verified "$V/numkong-v$NUMKONG_VERSION.tar.gz" \
	"https://github.com/ashvardanian/NumKong/archive/refs/tags/v$NUMKONG_VERSION.tar.gz" \
	"$NUMKONG_SHA256"
if [ ! -f "$SRC/numkong/include/numkong/numkong.h" ]; then
	rm -rf "$SRC/numkong"
	mkdir -p "$SRC/numkong"
	tar xzf "$V/numkong-v$NUMKONG_VERSION.tar.gz" -C "$SRC/numkong" --strip-components=1
fi

fetch_verified "$V/stringzilla-v$STRINGZILLA_VERSION.tar.gz" \
	"https://github.com/ashvardanian/stringzilla/archive/refs/tags/v$STRINGZILLA_VERSION.tar.gz" \
	"$STRINGZILLA_SHA256"
if [ ! -f "$SRC/stringzilla/include/stringzilla/stringzilla.h" ]; then
	rm -rf "$SRC/stringzilla"
	mkdir -p "$SRC/stringzilla"
	tar xzf "$V/stringzilla-v$STRINGZILLA_VERSION.tar.gz" -C "$SRC/stringzilla" --strip-components=1
fi

printf '%s\n' "$USEARCH_SHA256" > "$SRC/.verified-source-sha256"

printf '%s\n' "$SRC"

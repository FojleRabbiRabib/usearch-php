#!/usr/bin/env bash
# tools/fetch-dataset.sh — the single fetch+verify path for the recall-gate dataset.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Downloads the Fashion-MNIST images into vendor-data/fashion-mnist/ and
# verifies their SHA-256. Fashion-MNIST is MIT-licensed
# (https://github.com/zalandoresearch/fashion-mnist). The upstream repository
# does not tag data revisions, so the content hashes below ARE the pin: any
# upstream change to the bytes breaks the build of the quality gate rather
# than silently shifting recall numbers.
#
# The dataset is test material only. It is never shipped in the release
# archive, nothing in the extension links against it, and the vendor-data
# tree is reproducible from this script alone.
#
# Env: TRAIN_IMAGES_SHA256, TEST_IMAGES_SHA256.
set -euo pipefail

TRAIN_IMAGES_SHA256="${TRAIN_IMAGES_SHA256:-3aede38d61863908ad78613f6a32ed271626dd12800ba2636569512369268a84}"
TEST_IMAGES_SHA256="${TEST_IMAGES_SHA256:-346e55b948d973a97e58d2351dde16a484bd415d4595297633bb08f03db6a073}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
D="$ROOT/vendor-data/fashion-mnist"
URL_BASE="https://raw.githubusercontent.com/zalandoresearch/fashion-mnist/master/data/fashion"

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

# fetch_verified <file> <url> <expected-sha256>
fetch_verified() {
	local file="$1" url="$2" want="$3" actual
	if [ ! -f "$file" ]; then
		curl -fsSL --retry 3 --retry-delay 2 -o "$file" "$url"
	fi
	actual="$(calc_sha256 "$file")"
	if [ "$actual" != "$want" ]; then
		echo "FAIL: SHA256 mismatch for $(basename "$file") (expected $want, got $actual)" >&2
		rm -f "$file"
		exit 1
	fi
}

mkdir -p "$D"

fetch_verified "$D/train-images-idx3-ubyte.gz" \
	"$URL_BASE/train-images-idx3-ubyte.gz" \
	"$TRAIN_IMAGES_SHA256"

fetch_verified "$D/t10k-images-idx3-ubyte.gz" \
	"$URL_BASE/t10k-images-idx3-ubyte.gz" \
	"$TEST_IMAGES_SHA256"

printf '%s\n' "$D"

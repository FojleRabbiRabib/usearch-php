#!/usr/bin/env bash
# tools/gate-hardening.sh — assert the shared object's ELF hardening.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# RELRO, BIND_NOW, a non-executable stack, and no RPATH/RUNPATH. Run by tools/build.sh
# on the fresh build and again by the EL8 build after the module is stripped.
#
# Usage: tools/gate-hardening.sh [path/to/usearch.so]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODULE="${1:-$ROOT/modules/usearch.so}"

if [ ! -f "$MODULE" ]; then
	echo "FAIL: $MODULE not found; build first" >&2
	exit 1
fi

if [ "$(uname -s)" != "Linux" ]; then
	echo "SKIP: hardening gate is ELF-specific; run on Linux" >&2
	exit 0
fi

PHDRS="$(readelf -lW "$MODULE")"
DYNAMIC="$(readelf -dW "$MODULE")"

grep -q "GNU_RELRO" <<<"$PHDRS" || { echo "FAIL: missing GNU_RELRO" >&2; exit 1; }
if grep -qE "GNU_STACK.*RWE" <<<"$PHDRS"; then
	echo "FAIL: stack is executable" >&2
	exit 1
fi
grep -q "BIND_NOW" <<<"$DYNAMIC" || { echo "FAIL: missing BIND_NOW" >&2; exit 1; }
if grep -qE "RPATH|RUNPATH" <<<"$DYNAMIC"; then
	echo "FAIL: carries RPATH/RUNPATH" >&2
	exit 1
fi

echo "PASS: ELF hardening (RELRO, BIND_NOW, noexecstack, no RPATH)"

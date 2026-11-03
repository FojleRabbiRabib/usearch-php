#!/usr/bin/env bash
# tools/gate-linkage.sh — assert the shared object depends on the C library only.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# The C++ runtime is linked in statically, so the module must not name libstdc++ or
# libgcc_s as dependencies and must not reference GLIBCXX/CXXABI versions. An
# optional glibc ceiling asserts the build floor (the release build passes 2.28).
#
# Usage: tools/gate-linkage.sh [path/to/usearch.so] [max-glibc-version]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODULE="${1:-$ROOT/modules/usearch.so}"
MAX_GLIBC="${2:-}"

if [ ! -f "$MODULE" ]; then
	echo "FAIL: $MODULE not found; build first" >&2
	exit 1
fi

if [ "$(uname -s)" != "Linux" ]; then
	echo "SKIP: linkage gate is ELF-specific; run on Linux" >&2
	exit 0
fi

# 1. Only the C library family may be NEEDED (glibc, or musl's single libc).
NEEDED="$(readelf -dW "$MODULE" | grep -oE 'Shared library: \[[^]]+\]' | grep -oE '\[[^]]+\]' | tr -d '[]')"
UNEXPECTED="$(printf '%s\n' "$NEEDED" | grep -vE '^(libc\.so\.6|libm\.so\.6|libdl\.so\.2|libpthread\.so\.0|librt\.so\.1|ld-linux-[a-z0-9_-]+\.so\.[0-9]+|libc\.musl-[a-z0-9_]+\.so\.1)$' | grep -v '^$' || true)"
if [ -n "$UNEXPECTED" ]; then
	echo "FAIL: unexpected shared-library dependencies:" >&2
	printf '%s\n' "$UNEXPECTED" >&2
	exit 1
fi

# The dynamic symbol table is captured once: a pipeline into `grep -q` under pipefail
# reports the producer's SIGPIPE as "no match" and would let a failing check pass.
for tool in objdump nm; do
	command -v "$tool" >/dev/null 2>&1 || { echo "FAIL: $tool is required by the linkage gate" >&2; exit 1; }
done
DYNSYMS="$(objdump -T "$MODULE")"
UNDEFINED="$(nm -D --undefined-only "$MODULE")"

# 2. No C++ runtime version reference: nothing is resolved from a system libstdc++.
if grep -qE 'GLIBCXX_|CXXABI_' <<<"$DYNSYMS"; then
	echo "FAIL: module references GLIBCXX/CXXABI symbol versions; the C++ runtime is not static" >&2
	exit 1
fi

# 3. Optional glibc ceiling. A glibc module always carries versioned libc symbols, so
# a ceiling that cannot be read is a failure, never a pass.
GLIBC_MAX="$(grep -oE 'GLIBC_[0-9.]+' <<<"$DYNSYMS" | sort -Vu | tail -1 || true)"
GLIBC_MAX="${GLIBC_MAX#GLIBC_}"
if [ -n "$MAX_GLIBC" ]; then
	if [ -z "$GLIBC_MAX" ]; then
		echo "FAIL: no GLIBC_ symbol version found, so the ceiling GLIBC_$MAX_GLIBC cannot be verified" >&2
		exit 1
	fi
	if [ "$(printf '%s\n%s\n' "$GLIBC_MAX" "$MAX_GLIBC" | sort -V | tail -1)" != "$MAX_GLIBC" ]; then
		echo "FAIL: module needs GLIBC_$GLIBC_MAX, above the ceiling GLIBC_$MAX_GLIBC" >&2
		exit 1
	fi
	# Before glibc 2.34 pthreads live in libpthread; the archived libstdc++ reaches them
	# through weak references that stay unbound (locks silently skipped) unless the host
	# process happens to load libpthread, so a build targeting older glibc must name it.
	if [ "$(printf '%s\n%s\n' "$MAX_GLIBC" 2.34 | sort -V | head -1)" != "2.34" ] \
		&& grep -qE 'pthread_' <<<"$UNDEFINED" && ! grep -qx 'libpthread.so.0' <<<"$NEEDED"; then
		echo "FAIL: the module uses pthread symbols but does not NEED libpthread.so.0 (ceiling GLIBC_$MAX_GLIBC predates glibc 2.34)" >&2
		exit 1
	fi
fi

echo "PASS: C library only (${NEEDED//$'\n'/ }); no GLIBCXX/CXXABI reference; glibc symbol floor ${GLIBC_MAX:-none}"

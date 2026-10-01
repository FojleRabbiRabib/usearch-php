#!/usr/bin/env bash
# tools/gate-symbols.sh — assert the shared object hides everything but get_module.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# The vendored USearch core, NumKong, StringZilla, and the C++ runtime all link
# into usearch.so. None of their symbols may be visible to the host process: a
# second extension linking a different copy would otherwise bind to ours.
#
# Usage: tools/gate-symbols.sh [path/to/usearch.so]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODULE="${1:-$ROOT/modules/usearch.so}"

if [ ! -f "$MODULE" ]; then
	echo "FAIL: $MODULE not found; build first" >&2
	exit 1
fi

if [ "$(uname -s)" != "Linux" ]; then
	echo "SKIP: symbol gate is ELF-specific; run on Linux" >&2
	exit 0
fi

# 1. Exactly one exported dynamic symbol: get_module.
EXPORTED="$(nm -D --defined-only "$MODULE" | awk '{print $3}' | grep -v '^$' || true)"
COUNT="$(printf '%s\n' "$EXPORTED" | grep -c . || true)"
if [ "$COUNT" != "1" ] || [ "$EXPORTED" != "get_module" ]; then
	echo "FAIL: expected exactly one exported symbol (get_module), got $COUNT:" >&2
	printf '%s\n' "$EXPORTED" >&2
	exit 1
fi

# 2. No vendored C++ symbol leaked into the undefined set in a way that would
#    bind to a system copy at load time (the C++ runtime is allowed; upstream
#    API symbols are not, because they are defined, not undefined).
if nm -D --undefined-only "$MODULE" | grep -qE ' (usearch_|nk_|sz_)'; then
	echo "FAIL: vendored symbols are undefined, not statically linked:" >&2
	nm -D --undefined-only "$MODULE" | grep -E ' (usearch_|nk_|sz_)' >&2
	exit 1
fi

echo "PASS: exactly one exported symbol (get_module); vendored core statically resolved"

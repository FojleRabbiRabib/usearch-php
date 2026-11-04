#!/usr/bin/env bash
# tools/check-asset-names.sh — fail when two release assets share a file name.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# A GitHub release keeps one flat list of assets and rejects a second upload of the same
# name (HTTP 422), while the artifacts come from several directories (PIE zips, bare
# modules, debs, rpms). Names are compared case-insensitively, since the publisher may
# lower-case them.
#
# Usage: tools/check-asset-names.sh <dir>...
set -euo pipefail

[ "$#" -ge 1 ] || { echo "usage: tools/check-asset-names.sh <dir>..." >&2; exit 2; }

names=""
for dir in "$@"; do
	[ -d "$dir" ] || { echo "FAIL: $dir is not a directory" >&2; exit 1; }
	# *.phpapi files are build metadata next to the modules, never uploaded.
	listing="$(find "$dir" -type f ! -name '*.phpapi' -printf '%f\n')"
	names+="${listing}"$'\n'
done

sorted="$(grep -v '^$' <<<"$names" | tr '[:upper:]' '[:lower:]' | sort)" || true
[ -n "$sorted" ] || { echo "FAIL: no files found in: $*" >&2; exit 1; }

dupes="$(uniq -d <<<"$sorted")"
if [ -n "$dupes" ]; then
	echo "FAIL: asset names that appear more than once:" >&2
	echo "$dupes" >&2
	exit 1
fi
echo "ASSET NAMES UNIQUE ($(wc -l <<<"$sorted") files)"

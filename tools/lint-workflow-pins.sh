#!/usr/bin/env bash
# tools/lint-workflow-pins.sh — fail on any floating action, image or OIDC grant.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Rules, applied to the files given (workflow YAML and Dockerfiles):
#   1. every `uses: owner/repo@ref` is pinned to a 40-hex commit SHA (local `./` actions
#      and `docker://...@sha256:` references are allowed);
#   2. every Dockerfile `FROM` and `COPY --from=<image>` names an image by `@sha256:`
#      digest (`scratch` and a reference to an earlier build stage are allowed);
#   3. in a workflow, every `image:`, `container:` and `*image:` value (any case) carries
#      a digest or is an expression, and every well-known base image name (php, alpine,
#      debian, ubuntu, almalinux, fedora, rockylinux) written anywhere carries its own
#      digest. An image of another name inside a `docker run` line is not detected: pass
#      images through an `*_IMAGE` variable or a matrix `image:` entry, which are;
#   4. `id-token: write` and `write-all` appear only in the job named `finalize` of
#      release.yml, the one place that signs with keyless cosign, so no build job can mint
#      an OIDC token. Any other spelling of those grants is refused rather than parsed.
#
# Usage: tools/lint-workflow-pins.sh <file>...
# shellcheck disable=SC2094  # the linters read a file and name it in findings; never write it
set -euo pipefail

[ "$#" -ge 1 ] || { echo "usage: tools/lint-workflow-pins.sh <file>..." >&2; exit 2; }

failures=0
fail() { echo "FAIL: $1:$2: $3" >&2; failures=$((failures + 1)); }

image_name_re='(^|[^A-Za-z0-9_./{$-])(php|alpine|debian|ubuntu|almalinux|fedora|rockylinux):[A-Za-z0-9$][^[:space:]"'"'"']*'
image_key_re='^[[:space:]-]*([A-Za-z0-9_]*[Ii][Mm][Aa][Gg][Ee]|container):[[:space:]]*["'"'"']?([^[:space:]"'"'"'#]+)'
grant_ok_re='^[[:space:]]*id-token:[[:space:]]*["'"'"']?write["'"'"']?[[:space:]]*(#.*)?$'

lint_workflow() {
	local file="$1" n=0 job="" line ref rest token
	while IFS= read -r line || [ -n "$line" ]; do
		n=$((n + 1))
		[[ "$line" =~ ^[[:space:]]*# ]] && continue
		# A job id is a two-space-indented key under `jobs:`, possibly with a comment.
		if [[ "$line" =~ ^\ \ ([A-Za-z0-9_-]+):[[:space:]]*(#.*)?$ ]]; then
			job="${BASH_REMATCH[1]}"
		fi
		if [[ "$line" =~ uses:[[:space:]]*([^[:space:]#]+) ]]; then
			ref="${BASH_REMATCH[1]}"
			if [[ "$ref" == ./* || "$ref" == docker://*@sha256:* ]]; then
				:
			elif ! [[ "$ref" =~ @[0-9a-f]{40}$ ]]; then
				fail "$file" "$n" "action '$ref' is not pinned to a 40-hex commit SHA"
			fi
		fi
		if [[ "$line" =~ $image_key_re ]]; then
			ref="${BASH_REMATCH[2]}"
			# An expression is an indirection (a matrix entry); the entry itself is linted
			# on its own `image:` line.
			[[ "$ref" == \$\{\{* || "$ref" == *@sha256:* ]] || fail "$file" "$n" "image '$ref' has no @sha256 digest"
		fi
		if ! [[ "$line" =~ runs-on: ]]; then
			rest="$line"
			while [[ "$rest" =~ $image_name_re ]]; do
				token="${BASH_REMATCH[0]}"
				[[ "$token" == *@sha256:* ]] || fail "$file" "$n" "image reference '${token#[^a-z]}' has no @sha256 digest"
				rest="${rest#*"$token"}"
			done
		fi
		if [[ "$line" =~ id-token || "$line" =~ write-all ]]; then
			if [ "$job" != "finalize" ] || [ "$(basename "$file")" != "release.yml" ] || ! [[ "$line" =~ $grant_ok_re ]]; then
				fail "$file" "$n" "OIDC/write-all grant outside 'id-token: write' in the finalize job of release.yml (job '${job:-<top level>}')"
			fi
		fi
	done < "$file"
}

lint_dockerfile() {
	local file="$1" n=0 line image stages=" "
	while IFS= read -r line || [ -n "$line" ]; do
		n=$((n + 1))
		if [[ "$line" =~ ^[Ff][Rr][Oo][Mm][[:space:]]+(--platform=[^[:space:]]+[[:space:]]+)?([^[:space:]]+)([[:space:]]+[Aa][Ss][[:space:]]+([^[:space:]]+))? ]]; then
			image="${BASH_REMATCH[2]}"
			if [[ "$image" != scratch && "$stages" != *" $image "* && "$image" != *@sha256:* ]]; then
				fail "$file" "$n" "FROM '$image' has no @sha256 digest"
			fi
			if [ -n "${BASH_REMATCH[4]}" ]; then
				stages+="${BASH_REMATCH[4]} "
			fi
		fi
		if [[ "$line" =~ ^[Cc][Oo][Pp][Yy][[:space:]].*--from=([^[:space:]]+) ]]; then
			image="${BASH_REMATCH[1]}"
			if [[ "$stages" != *" $image "* && "$image" != *@sha256:* && "$image" =~ [/:] ]]; then
				fail "$file" "$n" "COPY --from='$image' has no @sha256 digest"
			fi
		fi
	done < "$file"
}

for file in "$@"; do
	[ -f "$file" ] || { echo "FAIL: $file not found" >&2; exit 1; }
	case "$(basename "$file")" in
		Dockerfile*|*.Dockerfile|Containerfile*|*.Containerfile) lint_dockerfile "$file" ;;
		*) lint_workflow "$file" ;;
	esac
done

if [ "$failures" -ne 0 ]; then
	echo "PIN LINT FAILED ($failures finding(s))" >&2
	exit 1
fi
echo "PIN LINT PASSED ($# file(s))"

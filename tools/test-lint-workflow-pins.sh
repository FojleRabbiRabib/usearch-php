#!/usr/bin/env bash
# tools/test-lint-workflow-pins.sh — unit test for tools/lint-workflow-pins.sh.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LINT="$ROOT/tools/lint-workflow-pins.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

SHA=0123456789abcdef0123456789abcdef01234567
DIGEST=sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef

failures=0
expect() { # expected-exit label file
	local want="$1" label="$2" file="$3" got=0
	"$LINT" "$file" >/dev/null 2>&1 || got=$?
	if [ "$got" -eq "$want" ]; then
		printf '  %-62s PASS\n' "$label"
	else
		printf '  %-62s FAIL (exit %s, wanted %s)\n' "$label" "$got" "$want"
		failures=$((failures + 1))
	fi
}
workflow() { printf '%s\n' "$@" > "$work/wf.yml"; }

workflow "jobs:" "  build:" "    steps:" "      - uses: actions/checkout@$SHA # v4"
expect 0 "an action pinned to a commit SHA passes" "$work/wf.yml"

workflow "jobs:" "  build:" "    steps:" "      - uses: actions/checkout@v4"
expect 1 "an action on a floating tag fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    steps:" "      - uses: actions/checkout@${SHA:0:39}"
expect 1 "an action on a short SHA fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    steps:" "      - uses: ./.github/actions/local"
expect 0 "a local action passes" "$work/wf.yml"

workflow "jobs:" "  build:" "    steps:" "      - run: docker run --rm \"php:\${{ matrix.php }}-alpine\" true"
expect 1 "a floating php image in a run step fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    steps:" "      - run: docker run --rm \"php:8.3-alpine@$DIGEST\" true"
expect 0 "a digest-pinned php image in a run step passes" "$work/wf.yml"

workflow "jobs:" "  build:" "    runs-on: ubuntu-22.04" "    steps:" "      - run: true"
expect 0 "a runs-on label is not an image" "$work/wf.yml"

workflow "jobs:" "  build:" "    container:" "      image: debian:bookworm"
expect 1 "a floating container image fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    env:" "      SIGN_IMAGE: almalinux:8@$DIGEST"
expect 0 "an IMAGE variable with a digest passes" "$work/wf.yml"

workflow "jobs:" "  build:" "    env:" "      SIGN_IMAGE: almalinux:8"
expect 1 "an IMAGE variable without a digest fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    env:" "      MUSL_IMAGE: \${{ matrix.image }}"
expect 0 "an IMAGE variable that is an expression passes" "$work/wf.yml"

workflow "jobs:" "  build:" "    strategy:" "      matrix:" "        include:" "          - image: 'php:8.3-alpine'"
expect 1 "a matrix image without a digest fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    steps:" "      - run: echo \"\${php:0:1}\""
expect 0 "a shell substring is not an image" "$work/wf.yml"

workflow "jobs:" "  build:" "    permissions:" "      id-token: write"
expect 1 "id-token: write in a build job fails" "$work/wf.yml"

printf '%s\n' "jobs:" "  finalize:" "    permissions:" "      id-token: write" > "$work/release.yml"
expect 0 "id-token: write in the finalize job of release.yml passes" "$work/release.yml"

workflow "jobs:" "  finalize:" "    permissions:" "      id-token: write"
expect 1 "id-token: write in a finalize job of another file fails" "$work/wf.yml"

printf '%s\n' "jobs:" "  build:" "    permissions:" "      id-token: \"write\"" > "$work/release.yml"
expect 1 "a quoted id-token grant in a build job fails" "$work/release.yml"

printf '%s\n' "jobs:" "  build:" "    permissions: { id-token: write }" > "$work/release.yml"
expect 1 "an inline id-token grant fails" "$work/release.yml"

printf '%s\n' "jobs:" "  build:" "    permissions: write-all" > "$work/release.yml"
expect 1 "write-all fails" "$work/release.yml"

printf '%s\n' "jobs:" "  finalize:" "    steps:" "      - run: true" "  sign: # signs" "    permissions: { id-token: write }" > "$work/release.yml"
expect 1 "a job key with a trailing comment does not inherit the previous job" "$work/release.yml"

workflow "jobs:" "  build:" "    strategy:" "      matrix:" "        include:" "          - image: node:20"
expect 1 "a matrix image of an unlisted name fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    container: node:20"
expect 1 "a string-form container fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    env:" "      tool_image: ghcr.io/x/y:latest"
expect 1 "a lowercase *_image key fails" "$work/wf.yml"

workflow "jobs:" "  build:" "    steps:" "      - run: docker run php:8.3-alpine@$DIGEST php:8.4-alpine"
expect 1 "a line with one pinned and one floating image fails" "$work/wf.yml"

workflow "permissions:" "  id-token: write" "jobs:" "  finalize:" "    steps:" "      - run: true"
expect 1 "id-token: write at the top level fails" "$work/wf.yml"

printf 'FROM almalinux:8@%s\nRUN true\n' "$DIGEST" > "$work/Dockerfile"
expect 0 "a Dockerfile FROM with a digest passes" "$work/Dockerfile"

printf 'FROM almalinux:8\nRUN true\n' > "$work/Dockerfile"
expect 1 "a Dockerfile FROM without a digest fails" "$work/Dockerfile"

printf 'FROM debian:bookworm@%s AS build\nFROM build\nFROM scratch\n' "$DIGEST" > "$work/Dockerfile"
expect 0 "a build-stage reference and scratch pass" "$work/Dockerfile"

printf 'from almalinux:8\n' > "$work/Dockerfile"
expect 1 "a lowercase from without a digest fails" "$work/Dockerfile"

printf 'FROM debian:bookworm@%s AS build\nCOPY --from=node:20 /a /b\n' "$DIGEST" > "$work/Dockerfile"
expect 1 "COPY --from of a floating image fails" "$work/Dockerfile"

printf 'FROM debian:bookworm@%s AS build\nCOPY --from=build /a /b\n' "$DIGEST" > "$work/Dockerfile"
expect 0 "COPY --from of a build stage passes" "$work/Dockerfile"

printf 'FROM almalinux:8\n' > "$work/tool.Dockerfile"
expect 1 "a *.Dockerfile is linted as a Dockerfile" "$work/tool.Dockerfile"

if [ "$failures" -ne 0 ]; then
	echo "PIN LINT TEST FAILED ($failures)" >&2
	exit 1
fi
echo "PIN LINT TEST PASSED"

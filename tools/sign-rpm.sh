#!/usr/bin/env bash
# tools/sign-rpm.sh — sign every rpm in a directory inside the pinned EL8 signing image.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Usage: tools/sign-rpm.sh <sums-file> <rpm-dir>
#   sums-file: `sha256sum` lines the build job published for the unsigned rpms; the rpms
#              in <rpm-dir> must match it exactly, or nothing is signed
#   rpm-dir:   holds the *.rpm files; they are signed in place
# Env: RPM_SIGNING_KEY (armoured secret key) and RPM_SIGNING_PASSPHRASE (one line), handed
#      to the container on standard input so they appear in neither a process listing nor
#      `docker inspect`;
#      SIGN_IMAGE=<image> signs with an image the caller built (and removes) instead of
#      building one, so a job signing several directories builds the image once.
set -euo pipefail

SUMS="${1:?usage: tools/sign-rpm.sh <sums-file> <rpm-dir>}"
DIR="${2:?usage: tools/sign-rpm.sh <sums-file> <rpm-dir>}"
: "${RPM_SIGNING_KEY:?RPM_SIGNING_KEY must be set}"
: "${RPM_SIGNING_PASSPHRASE:?RPM_SIGNING_PASSPHRASE must be set}"
[ -f "$SUMS" ] || { echo "FAIL: $SUMS not found" >&2; exit 1; }
[ -d "$DIR" ] || { echo "FAIL: $DIR is not a directory" >&2; exit 1; }

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SUMS_ABS="$(cd "$(dirname "$SUMS")" && pwd)/$(basename "$SUMS")"
DIR_ABS="$(cd "$DIR" && pwd)"

# The passphrase travels as the first line of standard input.
case "$RPM_SIGNING_PASSPHRASE" in
	*$'\n'*) echo "FAIL: the passphrase must be a single line" >&2; exit 1 ;;
esac

IMAGE="${SIGN_IMAGE:-}"
if [ -z "$IMAGE" ]; then
	IMAGE="usearch-sign:$$"
	docker build -q -t "$IMAGE" "$ROOT/tools/sign" >/dev/null
	trap 'docker rmi -f "$IMAGE" >/dev/null 2>&1 || true' EXIT
fi

printf '%s\n%s\n' "$RPM_SIGNING_PASSPHRASE" "$RPM_SIGNING_KEY" | docker run --rm -i \
	-e HOME=/tmp \
	--user "$(id -u):$(id -g)" \
	-v "$DIR_ABS:/rpms" -v "$SUMS_ABS:/sums:ro" -v "$ROOT/tools/sign/sign.sh:/sign.sh:ro" \
	"$IMAGE" bash /sign.sh

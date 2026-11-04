#!/usr/bin/env bash
# tools/test-sign-rpm.sh — prove rpm signing with a throwaway key, on every target distro.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Usage: tools/test-sign-rpm.sh [rpm-dir] [image...]
#   rpm-dir: built, unsigned rpms to sign copies of (default: dist/rpm)
#   image:   distros that must accept the signature (default: almalinux:8 almalinux:9
#            almalinux:10 fedora:44)
# A fresh RSA-4096 key is generated for the run and removed afterwards; no real key is read.
# Cases: sign and verify on each distro, an unsigned control that must not verify as signed,
# a tampered rpm, an unlisted extra rpm and a wrong passphrase (each must sign nothing).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:-$ROOT/dist/rpm}"
shift || true
IMAGES=("$@")
[ "${#IMAGES[@]}" -gt 0 ] || IMAGES=(almalinux:8 almalinux:9 almalinux:10 fedora:44)

shopt -s nullglob
srcs=("$SRC"/*.rpm)
[ "${#srcs[@]}" -gt 0 ] || { echo "FAIL: no rpm in $SRC (build with tools/build-rpm.sh)" >&2; exit 1; }

work="$(mktemp -d)"
export GNUPGHOME="$work/gnupg"
SIGN_IMAGE="usearch-sign-test:$$"
trap 'gpgconf --kill gpg-agent >/dev/null 2>&1 || true; docker rmi -f "$SIGN_IMAGE" >/dev/null 2>&1 || true; rm -rf "$work"' EXIT
# One image for every case; tools/sign-rpm.sh would otherwise build it once per call.
docker build -q -t "$SIGN_IMAGE" "$ROOT/tools/sign" >/dev/null
export SIGN_IMAGE
mkdir -m 700 "$GNUPGHOME"

PASS=throwaway-pass-$$
# The shape the real key will have: a certify-only primary key (kept offline) and a signing
# subkey, of which only the subkey is exported, so signatures carry the subkey's key ID.
gpg --batch --quiet --pinentry-mode loopback --passphrase "$PASS" \
	--quick-generate-key "Throwaway Test Key <throwaway@example.invalid>" rsa4096 cert never
listing="$(gpg --batch --list-secret-keys --with-colons)"
fpr="$(grep -m1 '^fpr:' <<<"$listing" | cut -d: -f10)"
gpg --batch --quiet --pinentry-mode loopback --passphrase "$PASS" \
	--quick-add-key "$fpr" rsa4096 sign never
RPM_SIGNING_KEY="$(gpg --batch --pinentry-mode loopback --passphrase "$PASS" --export-secret-subkeys --armor "$fpr")"
gpg --batch --export --armor "$fpr" > "$work/public.asc"
export RPM_SIGNING_KEY RPM_SIGNING_PASSPHRASE="$PASS"

failures=0
report() { # label status
	printf '  %-62s %s\n' "$1" "$2"
	[ "$2" = PASS ] || failures=$((failures + 1))
}
fresh() { # dir: a copy of the unsigned rpms and its sums file
	rm -rf "$1"
	mkdir -p "$1"
	cp -p "${srcs[@]}" "$1/"
	(cd "$1" && sha256sum -- *.rpm > "$work/sums")
}
has_signature() { [ "$(rpm -qp --qf '%{RSAHEADER:pgpsig}' "$1" 2>/dev/null)" != "(none)" ]; }

echo "[sign]"
fresh "$work/signed"
if "$ROOT/tools/sign-rpm.sh" "$work/sums" "$work/signed" >"$work/sign.log" 2>&1; then report "signing the listed rpms succeeds" PASS; else cat "$work/sign.log" >&2; report "signing the listed rpms succeeds" FAIL; fi

echo "[verify on each distro]"
fresh "$work/unsigned"
for image in "${IMAGES[@]}"; do
	out="$(docker run --rm -v "$work/signed:/signed:ro" -v "$work/unsigned:/unsigned:ro" -v "$work/public.asc:/public.asc:ro" \
		"$image" bash -c '
			set -u
			rpmkeys --import /public.asc || { echo IMPORT-FAILED; exit 1; }
			for f in /signed/*.rpm; do
				rpmkeys --checksig "$f" | grep -q "signatures OK" || { echo "NOT-OK: $f"; exit 1; }
			done
			for f in /unsigned/*.rpm; do
				if rpmkeys --checksig "$f" | grep -q "signatures OK"; then echo "UNSIGNED-ACCEPTED: $f"; exit 1; fi
			done
			echo VERIFIED' 2>&1)" || true
	if [ "${out##*$'\n'}" = VERIFIED ]; then report "$image accepts signed rpms and not the unsigned control" PASS; else echo "$out" >&2; report "$image accepts signed rpms and not the unsigned control" FAIL; fi
done

echo "[the log never carries the key or the passphrase]"
if grep -qF -e "$PASS" -e "PRIVATE KEY" "$work/sign.log"; then report "no secret appears in the signing log" FAIL; else report "no secret appears in the signing log" PASS; fi

# refuse <label> <dir> <expected message> [sums-file] [passphrase]: the run must fail for the
# stated reason, and not one rpm in the directory may carry a signature afterwards.
refuse() {
	local label="$1" dir="$2" msg="$3" sums="${4:-$work/sums}" pass="${5:-$PASS}" log="$work/refuse.log" untouched=1 f
	if RPM_SIGNING_PASSPHRASE="$pass" "$ROOT/tools/sign-rpm.sh" "$sums" "$dir" >"$log" 2>&1; then
		report "$label" FAIL
		return
	fi
	if ! grep -qF -- "$msg" "$log"; then
		cat "$log" >&2
		report "$label (refused for another reason)" FAIL
		return
	fi
	for f in "$dir"/*.rpm; do has_signature "$f" && untouched=0; done
	if [ "$untouched" -eq 1 ]; then report "$label" PASS; else report "$label (an rpm got signed)" FAIL; fi
}

echo "[refusals sign nothing]"
fresh "$work/tamper"
printf 'x' >> "$work/tamper/$(basename "${srcs[0]}")"
refuse "a tampered rpm is refused" "$work/tamper" "does not match the build job's SHA-256"

fresh "$work/extra"
cp -p "$work/extra/$(basename "${srcs[0]}")" "$work/extra/extra-0-0.x86_64.rpm"
refuse "an rpm missing from the build list is refused" "$work/extra" "differ from the build job's list"

fresh "$work/absent"
rm "$work/absent/$(basename "${srcs[0]}")"
refuse "a listed rpm that was not delivered is refused" "$work/absent" "differ from the build job's list"

fresh "$work/empty"
: > "$work/empty-sums"
refuse "an empty hash list is refused" "$work/empty" "differ from the build job's list" "$work/empty-sums"

fresh "$work/pass"
refuse "a wrong passphrase signs nothing" "$work/pass" "Bad passphrase" "$work/sums" wrong

if [ "$failures" -ne 0 ]; then
	echo "RPM SIGNING TEST FAILED ($failures)" >&2
	exit 1
fi
echo "RPM SIGNING TEST PASSED"

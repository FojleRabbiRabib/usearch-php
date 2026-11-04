#!/usr/bin/env bash
# tools/sign/sign.sh — runs inside the signing image (see tools/sign-rpm.sh).
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Signs every rpm in /rpms. The secrets arrive on standard input, never in the environment
# or the command line, so neither `docker inspect` nor a process listing shows them: the
# first line is the passphrase, the rest is the ASCII-armoured secret key. First it checks
# the rpms against /sums, the SHA-256 list the build job published: the set of files must
# match exactly and every digest must agree, so a package swapped after the build is never
# signed. The key lives in a throwaway keyring on memory-backed storage and is wiped on exit.
set -euo pipefail

IFS= read -r RPM_SIGNING_PASSPHRASE || true
RPM_SIGNING_KEY="$(cat)"
: "${RPM_SIGNING_PASSPHRASE:?no passphrase on standard input}"
: "${RPM_SIGNING_KEY:?no key on standard input}"
umask 077

cd /rpms
shopt -s nullglob
rpms=(*.rpm)
[ "${#rpms[@]}" -gt 0 ] || { echo "FAIL: no rpm to sign in /rpms" >&2; exit 1; }

# The listed set and the delivered set must be identical.
listed="$(sed -E 's/^[0-9a-f]{64} [ *]//' /sums | sort)"
delivered="$(printf '%s\n' "${rpms[@]}" | sort)"
if [ "$listed" != "$delivered" ]; then
	echo "FAIL: the rpms to sign differ from the build job's list" >&2
	diff <(printf '%s\n' "$listed") <(printf '%s\n' "$delivered") >&2 || true
	exit 1
fi
(cd /rpms && sha256sum -c /sums >/dev/null) || { echo "FAIL: an rpm does not match the build job's SHA-256" >&2; exit 1; }

export GNUPGHOME
GNUPGHOME="$(mktemp -d /dev/shm/gnupg.XXXXXX)"
RPMDB="$(mktemp -d /dev/shm/rpmdb.XXXXXX)"
cleanup() {
	gpgconf --kill gpg-agent >/dev/null 2>&1 || true
	rm -rf "$GNUPGHOME" "$RPMDB"
}
trap cleanup EXIT

printf '%s' "$RPM_SIGNING_KEY" | gpg --batch --quiet --import
listing="$(gpg --batch --list-secret-keys --with-colons)"
fpr="$(grep -m1 '^fpr:' <<<"$listing" | cut -d: -f10)" || true
[ -n "$fpr" ] || { echo "FAIL: no secret key in RPM_SIGNING_KEY" >&2; exit 1; }
# The signature names the key that made it: the signing subkey when the primary key is
# kept offline and only subkeys were exported, so every key in the keyring is acceptable.
keyids="$(grep '^fpr:' <<<"$listing" | cut -d: -f10 | cut -c25- | tr '[:upper:]' '[:lower:]')"
printf '%s' "$RPM_SIGNING_PASSPHRASE" > "$GNUPGHOME/passphrase"

for f in "${rpms[@]}"; do
	rpmsign --addsign \
		--define "_gpg_path $GNUPGHOME" \
		--define "_gpg_name $fpr" \
		--define "_gpg_digest_algo sha256" \
		--define "_gpg_sign_cmd_extra_args --pinentry-mode loopback --passphrase-file $GNUPGHOME/passphrase" \
		"$f" >/dev/null
	sig="$(rpm -qp --qf '%{RSAHEADER:pgpsig}' "$f")"
	signed_by=""
	if [[ "$sig" =~ Key\ ID\ ([0-9a-f]{16}) ]] && grep -qx "${BASH_REMATCH[1]}" <<<"$keyids"; then
		signed_by="${BASH_REMATCH[1]}"
	fi
	[ -n "$signed_by" ] || { echo "FAIL: $f is not signed by a key from RPM_SIGNING_KEY (got: $sig)" >&2; exit 1; }
	echo "  signed $f (key $signed_by)"
done

# Check against the key's own public half in a scratch rpm database.
gpg --batch --export --armor "$fpr" > "$GNUPGHOME/public.asc"
rpmkeys --dbpath "$RPMDB" --import "$GNUPGHOME/public.asc"
for f in "${rpms[@]}"; do
	out="$(rpmkeys --dbpath "$RPMDB" --checksig "$f")"
	grep -q 'signatures OK' <<<"$out" || { echo "FAIL: rpmkeys does not accept $f: $out" >&2; exit 1; }
done
echo "RPM SIGNING COMPLETED (${#rpms[@]} package(s), key ${signed_by})"

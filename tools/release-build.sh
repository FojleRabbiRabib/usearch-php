#!/usr/bin/env bash
# tools/release-build.sh — assemble release assets for the supported PHP ABIs.
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Builds the extension per ABI, runs the behavioural and metric gates against
# the freshly built module, packages PIE-canonical zip archives wrapping
# `usearch.so` (per the PIE release archive specification), emits bare .so
# files for direct download, and generates SHA256SUMS and provenance records
# under build/dist/.
#
# Env: OUT_DIR (default: build/dist), ARCH (default: from uname -m),
#      ABIS (space-separated subset, default: "8.3 8.4 8.5"),
#      LIBC (glibc | musl | bsdlibc; default: detected from the build host),
#      TS (nts | zts; default: nts — PIE matches the thread-safety segment),
#      RELEASE_TAG (optional, e.g. v0.1.0 — PIE resolves packages by the full
#      tag version, so archives are additionally published under that name),
#      SKIP_GATES (set to 1 to skip the per-ABI gates; the CI musl container
#      uses it only when the container PHP cannot run the full suite).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${OUT_DIR:-$ROOT/build/dist}"
ARCH="${ARCH:-$(uname -m)}"

TS="${TS:-nts}"
case "$TS" in
	nts|zts) ;;
	*) echo "FAIL: unsupported TS '$TS' (supported: nts, zts)" >&2; exit 2 ;;
esac

OS_NAME="$(uname -s)"
case "$OS_NAME" in
	Linux) OS_SEG="${OS_SEG:-linux}" ;;
	Darwin) OS_SEG="${OS_SEG:-darwin}" ;;
	*) echo "FAIL: unsupported OS '$OS_NAME' (supported: Linux, Darwin)" >&2; exit 2 ;;
esac

# PIE's normalized architecture enum spells aarch64 as arm64; artifacts must
# exist under that spelling or PIE never resolves them.
PIE_ARCH="${ARCH/aarch64/arm64}"

# PIE encodes the libc flavour in the archive name; detect it from the build
# host when the caller has not pinned it. The musl dynamic loader is the
# filesystem marker for Alpine — parsing `ldd --version` would not do, since
# musl's ldd exits non-zero under `set -o pipefail` even as it prints the
# banner.
if [ -n "${LIBC:-}" ]; then
	case "$LIBC" in
		glibc|musl|bsdlibc) ;;
		*) echo "FAIL: unsupported LIBC '$LIBC' (supported: glibc, musl, bsdlibc)" >&2; exit 2 ;;
	esac
elif [ "$OS_SEG" = "darwin" ]; then
	LIBC=bsdlibc
elif ls /lib/ld-musl-*.so.* >/dev/null 2>&1; then
	LIBC=musl
else
	LIBC=glibc
fi

command -v zip >/dev/null 2>&1 || {
	echo "FAIL: zip utility not found; install zip" >&2
	exit 1
}

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

read -ra ABIS <<< "${ABIS:-8.3 8.4 8.5}"
[ "${#ABIS[@]}" -gt 0 ] || {
	echo "FAIL: ABIS resolved to an empty set" >&2
	exit 2
}
for abi in "${ABIS[@]}"; do
	case "$abi" in
		8.3|8.4|8.5) ;;
		*) echo "FAIL: unsupported ABI '$abi' (supported: 8.3, 8.4, 8.5)" >&2; exit 2 ;;
	esac
done

EXT_VERSION="$(awk -F'"' '/#define PHP_USEARCH_VERSION/ {print $2}' "$ROOT/php_usearch.h")"
if [ -z "$EXT_VERSION" ]; then
	echo "FAIL: could not determine PHP_USEARCH_VERSION from php_usearch.h" >&2
	exit 1
fi

echo ">> Preparing release build for usearch-php v$EXT_VERSION ($ARCH, $LIBC, $TS)"
# Fresh run: clear stale assets once. build/dist cannot be created up front —
# `build/` is phpize's own directory, and tools/build.sh's `phpize --clean`
# wipes it per ABI — so the output directory is (re)created after each build.
rm -rf "$OUT_DIR"

for php_ver in "${ABIS[@]}"; do
	echo "==> Building and gating PHP $php_ver"
	"$ROOT/tools/build.sh" "$php_ver"

	SRC_SO="$ROOT/modules/usearch.so"
	if [ ! -f "$SRC_SO" ]; then
		echo "FAIL: expected build output $SRC_SO not found" >&2
		exit 1
	fi

	if [ "${SKIP_GATES:-0}" != "1" ]; then
		PHP_BIN="php$php_ver"
		command -v "$PHP_BIN" >/dev/null 2>&1 || PHP_BIN=php
		"$ROOT/tools/test-phpt.php" "$PHP_BIN"
		"$PHP_BIN" -n -d extension="$SRC_SO" "$ROOT/tests/quality/metrics.php"
	fi

	# phpize --clean during the build removed build/ wholesale; recreate the
	# output directory now that the module exists.
	mkdir -p "$OUT_DIR"

	# 1. PIE-canonical release archive:
	# php_{ExtensionName}-{Version}_php{PhpVersion}-{Arch}-{OS}-{Libc}-{TSMode}.zip
	# {Arch} is PIE's normalized enum spelling (arm64, never aarch64). Archive
	# must contain `usearch.so`.
	PIE_ZIP_NAME="php_usearch-${EXT_VERSION}_php${php_ver}-${PIE_ARCH}-${OS_SEG}-${LIBC}-${TS}.zip"
	TMP_STAGE="$(mktemp -d)"
	cp "$SRC_SO" "$TMP_STAGE/usearch.so"
	(
		cd "$TMP_STAGE"
		zip -q -9 "$OUT_DIR/$PIE_ZIP_NAME" usearch.so
	)
	rm -rf "$TMP_STAGE"
	echo "   packaged PIE asset: $PIE_ZIP_NAME"

	# Host-arch alias so direct downloaders who expect the uname spelling
	# (aarch64) also find it.
	if [ "$PIE_ARCH" != "$ARCH" ]; then
		ALIAS_ZIP_NAME="$(printf 'php_usearch-%s_php%s-%s-%s-%s-%s.zip' \
			"$EXT_VERSION" "$php_ver" "$ARCH" "$OS_SEG" "$LIBC" "$TS" | tr '[:upper:]' '[:lower:]')"
		cp "$OUT_DIR/$PIE_ZIP_NAME" "$OUT_DIR/$ALIAS_ZIP_NAME"
		echo "   packaged PIE asset (host-arch alias): $ALIAS_ZIP_NAME"
	fi

	# PIE resolves the package by the tag's full version, so publish the
	# identical archive under that name too (lowercased, matching PIE).
	if [ -n "${RELEASE_TAG:-}" ]; then
		PIE_TAG_ZIP_NAME="$(printf 'php_usearch-%s_php%s-%s-%s-%s-%s.zip' \
			"$RELEASE_TAG" "$php_ver" "$PIE_ARCH" "$OS_SEG" "$LIBC" "$TS" | tr '[:upper:]' '[:lower:]')"
		cp "$OUT_DIR/$PIE_ZIP_NAME" "$OUT_DIR/$PIE_TAG_ZIP_NAME"
		echo "   packaged PIE asset (tag-version name): $PIE_TAG_ZIP_NAME"
	fi

	# 2. Direct-download bare .so. musl and macOS artifacts carry their
	# platform in the name so the families cannot collide.
	if [ "$OS_SEG" = "darwin" ]; then
		BARE_SO_NAME="usearch-php${php_ver}-darwin-${ARCH}.so"
	elif [ "$LIBC" = "musl" ]; then
		BARE_SO_NAME="usearch-php${php_ver}-linux-musl-${ARCH}.so"
	else
		BARE_SO_NAME="usearch-php${php_ver}-linux-${ARCH}.so"
	fi
	cp "$SRC_SO" "$OUT_DIR/$BARE_SO_NAME"
	echo "   emitted bare asset: $BARE_SO_NAME"

	# 3. Provenance record: what built this, against what, bound to which
	# glibc floor — everything a bug report needs before the reporter speaks.
	PHP_CONFIG_BIN="php-config$php_ver"
	command -v "$PHP_CONFIG_BIN" >/dev/null 2>&1 || PHP_CONFIG_BIN=php-config
	PHP_INC="$("$PHP_CONFIG_BIN" --include-dir 2>/dev/null || echo "")"
	PHP_API="$(awk '$1 == "#define" && $2 == "ZEND_MODULE_API_NO" { print $3; exit }' \
		"$PHP_INC/Zend/zend_modules.h" 2>/dev/null || echo "unknown")"
	SO_SHA="$(calc_sha256 "$SRC_SO")"
	GIT_REV="${GIT_REV:-$(git rev-parse HEAD 2>/dev/null || echo "unknown")}"
	GLIBC_MAX="$(objdump -T "$SRC_SO" 2>/dev/null | grep -oE 'GLIBC_[0-9.]+' | sort -Vu | tail -1 || true)"

	if [ "$OS_SEG" = "darwin" ]; then
		PROV_NAME="usearch-php${php_ver}-darwin-${ARCH}.provenance"
	elif [ "$LIBC" = "musl" ]; then
		PROV_NAME="usearch-php${php_ver}-${ARCH}-musl.provenance"
	else
		PROV_NAME="usearch-php${php_ver}-${ARCH}.provenance"
	fi
	cat > "$OUT_DIR/$PROV_NAME" <<-EOF
	version=$EXT_VERSION
	php=$php_ver
	php_api=$PHP_API
	arch=$ARCH
	os=$OS_SEG
	libc=$LIBC
	glibc_symbol=${GLIBC_MAX:-none}
	commit=$GIT_REV
	sha256=$SO_SHA
	EOF
done

echo "==> Generating SHA256SUMS"
(
	cd "$OUT_DIR"
	if command -v sha256sum >/dev/null 2>&1; then
		find . -maxdepth 1 -type f ! -name 'SHA256SUMS*' -print | sort | xargs sha256sum > /tmp/usv-sums.tmp
	else
		find . -maxdepth 1 -type f ! -name 'SHA256SUMS*' -print | sort | xargs shasum -a 256 > /tmp/usv-sums.tmp
	fi
	mv /tmp/usv-sums.tmp SHA256SUMS
)

echo ">> Release assets assembled in $OUT_DIR:"
ls -lh "$OUT_DIR"

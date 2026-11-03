#!/usr/bin/env bash
# tools/deb/build.sh — runs inside the Debian packaging image (see tools/build-deb.sh).
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Wraps the EL8-built module in php<N>-usearch_<version>_<arch>.deb without touching its
# bytes, lints the result, proves a second assembly is byte-identical, and copies the
# package to /out. Inputs come from the environment: PHP_VERSION, DEB_VERSION, DEB_ARCH,
# PHP_API, SOURCE_DATE_EPOCH; the module is /in/module.so and the repository is mounted
# read-only at /src.
set -euo pipefail

PHP_VER="${PHP_VERSION:?PHP_VERSION must be set}"
VERSION="${DEB_VERSION:?DEB_VERSION must be set}"
ARCH="${DEB_ARCH:?DEB_ARCH must be set}"
API="${PHP_API:?PHP_API must be set}"
SDE="${SOURCE_DATE_EPOCH:?SOURCE_DATE_EPOCH must be set}"

PKG="php${PHP_VER}-usearch"
MAINTAINER='Fojle Rabbi (Rabib) <fojle.rabbi.official@gmail.com>'
HOMEPAGE='https://github.com/FojleRabbiRabib/usearch-php'
export LC_ALL=C

# The libc6 floor is read from the module, not assumed: the highest versioned symbol it uses.
GLIBC_FLOOR="$(objdump -T /in/module.so | grep -oE 'GLIBC_[0-9.]+' | sort -Vu | tail -1 || true)"
GLIBC_FLOOR="${GLIBC_FLOOR#GLIBC_}"
[ -n "$GLIBC_FLOOR" ] || { echo "FAIL: no GLIBC_ symbol version found in the module" >&2; exit 1; }

FILE_VERSION="${VERSION//\~/.}"
DEB_NAME="${PKG}_${FILE_VERSION}_${ARCH}.deb"

# assemble <root> <deb>: build the package tree under <root> and pack it to <deb>. Every
# mode and timestamp is set explicitly, so the result does not depend on the caller's umask
# or on the filesystem underneath.
assemble() {
	local root="$1" deb="$2"
	local doc="$root/usr/share/doc/$PKG"
	umask 022
	rm -rf "$root"

	install -d -m 0755 "$root" "$root/usr/lib/php/$API" "$root/etc/php/$PHP_VER/mods-available" \
		"$doc" "$root/usr/share/lintian/overrides"
	install -m 0644 /in/module.so "$root/usr/lib/php/$API/usearch.so"

	cat > "$root/etc/php/$PHP_VER/mods-available/usearch.ini" <<'EOF'
; configuration for php usearch module
; priority=20
extension=usearch.so
EOF

	install -m 0644 /src/LICENSE "$doc/LICENSE"
	install -m 0644 /src/NOTICE "$doc/NOTICE"
	gzip -9n -c /src/CHANGELOG.md > "$doc/changelog.gz"

	cat > "$doc/copyright" <<'EOF'
Format: https://www.debian.org/doc/packaging-manuals/copyright-format/1.0/
Upstream-Name: usearch-php
Upstream-Contact: Fojle Rabbi (Rabib) <fojle.rabbi.official@gmail.com>
Source: https://github.com/FojleRabbiRabib/usearch-php

Files: *
Copyright: 2026 Fojle Rabbi (Rabib)
           2023-2026 Unum Cloud (USearch, statically linked)
           2024-2026 Ash Vardanian (NumKong and StringZilla, statically linked)
License: Apache-2.0

License: Apache-2.0
 Licensed under the Apache License, Version 2.0. The full text is in
 /usr/share/common-licenses/Apache-2.0 and in the LICENSE file of this package;
 the attribution notices that must travel with redistribution are in NOTICE.
EOF

	local date_rfc
	date_rfc="$(date -u -d "@$SDE" '+%a, %d %b %Y %H:%M:%S +0000')"
	{
		printf '%s (%s) unstable; urgency=medium\n\n' "$PKG" "$VERSION"
		printf '  * Packaged from the upstream release; see changelog.gz for its changes.\n\n'
		printf ' -- %s  %s\n' "$MAINTAINER" "$date_rfc"
	} | gzip -9n > "$doc/changelog.Debian.gz"

	cat > "$root/usr/share/lintian/overrides/$PKG" <<EOF
# This package is published from its own repository, never uploaded to the Debian
# archive, so an ITP bug number cannot be closed by its first changelog entry.
$PKG: initial-upload-closes-no-bugs
EOF

	local installed_size
	installed_size="$(du -sk --apparent-size "$root" | cut -f1)"
	install -d -m 0755 "$root/DEBIAN"

	cat > "$root/DEBIAN/control" <<EOF
Package: $PKG
Version: $VERSION
Architecture: $ARCH
Maintainer: $MAINTAINER
Installed-Size: $installed_size
Depends: php-common (>= 1:81~), phpapi-$API, libc6 (>= $GLIBC_FLOOR)
Provides: php-usearch
Section: php
Priority: optional
Homepage: $HOMEPAGE
Description: in-process vector similarity search for PHP (USearch)
 A native PHP extension exposing the USearch HNSW vector index: add, search,
 save, load and memory-map indexes in-process, with SIMD kernels selected at
 run time. The C++ runtime is linked in statically; the module depends on the
 C library only.
EOF

	printf '/etc/php/%s/mods-available/usearch.ini\n' "$PHP_VER" > "$root/DEBIAN/conffiles"

	# php_invoke enables the module unless the administrator disabled it by hand (the helper
	# records that), so an upgrade never overrides a deliberate phpdismod.
	cat > "$root/DEBIAN/postinst" <<EOF
#!/bin/sh
set -e

if [ "\$1" = "configure" ]; then
    if [ -e /usr/lib/php/php-maintscript-helper ]; then
        . /usr/lib/php/php-maintscript-helper
        php_invoke enmod $PHP_VER ALL usearch
    fi
fi

exit 0
EOF

	cat > "$root/DEBIAN/postrm" <<EOF
#!/bin/sh
set -e

if [ "\$1" = "remove" ]; then
    if [ -e /usr/lib/php/php-maintscript-helper ]; then
        . /usr/lib/php/php-maintscript-helper
        php_invoke dismod $PHP_VER ALL usearch
    fi
fi

if [ "\$1" = "purge" ]; then
    inifile=/etc/php/$PHP_VER/mods-available/usearch.ini
    rm -f "\$inifile" "\$inifile.dpkg-old" "\$inifile.dpkg-dist" "\$inifile.dpkg-bak"
    if [ -d /etc/php ]; then
        find /etc/php -type l -lname "\$inifile" -delete
    fi
    for dir in /etc/php/$PHP_VER/mods-available /etc/php/$PHP_VER /etc/php; do
        if [ ! -L "\$dir" ] && [ -d "\$dir" ]; then
            rmdir --ignore-fail-on-non-empty "\$dir"
        fi
    done
fi

exit 0
EOF

	cat > "$root/DEBIAN/triggers" <<EOF
activate-noawait /etc/php/$PHP_VER/apache2/conf.d
activate-noawait /etc/php/$PHP_VER/apache2filter/conf.d
activate-noawait /etc/php/$PHP_VER/fpm/conf.d
EOF

	(cd "$root" && find . -type f ! -path './DEBIAN/*' -printf '%P\0' | sort -z | xargs -0 md5sum) > "$root/DEBIAN/md5sums"

	find "$root" -type d -exec chmod 0755 {} +
	find "$root" -type f -exec chmod 0644 {} +
	chmod 0755 "$root/DEBIAN/postinst" "$root/DEBIAN/postrm"
	find "$root" -exec touch -h -d "@$SDE" {} +

	dpkg-deb --root-owner-group -Zxz -z6 --build "$root" "$deb" >/dev/null
}

assemble /tmp/pkgroot-a "/tmp/$DEB_NAME"

# A second assembly under a hostile umask must give the same bytes; this is the
# byte-identical guarantee, checked on every build rather than assumed.
(umask 077; assemble /tmp/pkgroot-b /tmp/second.deb)
if ! cmp -s "/tmp/$DEB_NAME" /tmp/second.deb; then
	echo "FAIL: two assemblies of the same module gave different packages" >&2
	exit 1
fi

lintian --fail-on error,warning --tag-display-limit 0 "/tmp/$DEB_NAME"

install -m 0644 "/tmp/$DEB_NAME" /out/
echo "DEB BUILD COMPLETED ($DEB_NAME, libc6 >= $GLIBC_FLOOR, phpapi-$API, reproducible)"

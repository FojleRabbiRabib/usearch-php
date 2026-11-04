#!/usr/bin/env bash
# tools/rpm/build.sh — runs inside the EL8 rpm packaging image (see tools/build-rpm.sh).
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Wraps the EL8-built module in the two Remi layouts (php-usearch and php<N>-php-usearch)
# without touching its bytes, lints both packages, proves a second assembly is
# byte-identical, and copies them to /out. Inputs come from the environment: PHP_VERSION,
# PKG_VERSION, PKG_RELEASE, PHP_API, SOURCE_DATE_EPOCH; the module is /in/module.so and the
# repository is mounted read-only at /src. Signing is a separate, isolated job.
set -euo pipefail

PHP_VER="${PHP_VERSION:?PHP_VERSION must be set}"
VERSION="${PKG_VERSION:?PKG_VERSION must be set}"
RELEASE="${PKG_RELEASE:?PKG_RELEASE must be set}"
API="${PHP_API:?PHP_API must be set}"
SDE="${SOURCE_DATE_EPOCH:?SOURCE_DATE_EPOCH must be set}"
PHP_NUM="${PHP_VER//./}"
export LC_ALL=C SOURCE_DATE_EPOCH="$SDE"

CHANGELOG_DATE="$(date -u -d "@$SDE" '+%a %b %d %Y')"

# build_one <layout> <topdir>: build one layout's package under <topdir>.
build_one() {
	local layout="$1" topdir="$2" release="$RELEASE"
	rm -rf "$topdir"
	mkdir -p "$topdir"/{BUILD,RPMS,SOURCES,SPECS,SRPMS}
	# The base layout has no PHP version in its name, so the Release carries it; the
	# versioned layout's name already does.
	[ "$layout" = "base" ] && release="$RELEASE.php$PHP_NUM"

	install -m 0644 /in/module.so "$topdir/SOURCES/usearch.so"
	cat > "$topdir/SOURCES/usearch.ini" <<'EOF'
; configuration for php usearch module
; priority=40
extension=usearch.so
EOF
	# Only the install test sets this: its "newer" package must differ in the ini too, or an
	# upgrade cannot tell %config(noreplace) from a plain %config.
	[ "${PKG_TEST_BUILD:-0}" = "1" ] && echo '; test build' >> "$topdir/SOURCES/usearch.ini"
	install -m 0644 /src/LICENSE /src/NOTICE /src/CHANGELOG.md "$topdir/SOURCES/"
	install -m 0644 /src/tools/rpm/usearch.spec "$topdir/SPECS/usearch.spec"
	find "$topdir" -exec touch -h -d "@$SDE" {} +

	rpmbuild -bb "$topdir/SPECS/usearch.spec" --quiet \
		--define "_topdir $topdir" \
		--define "layout $layout" --define "phpver $PHP_VER" --define "abi $API" \
		--define "pkgversion $VERSION" --define "pkgrelease $release" \
		--define "changelog_date $CHANGELOG_DATE"
}

for layout in base versioned; do
	umask 022
	build_one "$layout" "/tmp/rpm-a-$layout"
	rpm_a="$(find "/tmp/rpm-a-$layout/RPMS" -name '*.rpm')"
	[ -n "$rpm_a" ] || { echo "FAIL: rpmbuild produced no package for the $layout layout" >&2; exit 1; }

	# A second assembly under a hostile umask, in another directory, must give the same
	# bytes: the byte-identical guarantee is checked on every build, not assumed.
	(umask 077; build_one "$layout" "/tmp/rpm-b-$layout")
	rpm_b="$(find "/tmp/rpm-b-$layout/RPMS" -name '*.rpm')"
	if ! cmp -s "$rpm_a" "$rpm_b"; then
		echo "FAIL: two assemblies of the $layout layout gave different packages" >&2
		exit 1
	fi

	# Clean means rpmlint ran to its summary and counted nothing; a crash or a broken
	# rpmlintrc prints no findings either, so the absence of findings proves nothing.
	lint_status=0
	lint="$(rpmlint -f /src/tools/rpm/rpmlintrc "$rpm_a" 2>&1)" || lint_status=$?
	printf '%s\n' "$lint"
	if [ "$lint_status" -ne 0 ] || grep -qE ': [WE]: ' <<<"$lint" \
		|| ! grep -qE ' 0 errors, 0 warnings' <<<"$lint"; then
		echo "FAIL: rpmlint did not report a clean run for $(basename "$rpm_a") (exit $lint_status)" >&2
		exit 1
	fi

	install -m 0644 "$rpm_a" /out/
	echo "  built $(basename "$rpm_a")"
done
echo "RPM BUILD COMPLETED (PHP $PHP_VER, $VERSION-$RELEASE, abi $API, reproducible)"

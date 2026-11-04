#!/usr/bin/env bash
# tools/rpm/test.sh — runs inside a clean EL or Fedora container (see tools/test-rpm.sh).
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Environment: PHP_VER, LAYOUT (base | versioned), EXPECT_REFUSE (0 | 1). Packages: /pkg.rpm
# and, for the upgrade step, /newer.rpm (the same module at a higher version). With
# EXPECT_REFUSE=1 the package targets a different PHP than the one installed, and dnf must
# refuse it; otherwise the full lifecycle is exercised.
set -euo pipefail

PHP_VER="${PHP_VER:?PHP_VER must be set}"
LAYOUT="${LAYOUT:?LAYOUT must be set}"
EXPECT_REFUSE="${EXPECT_REFUSE:-0}"
NUM="${PHP_VER//./}"

if [ "$LAYOUT" = "versioned" ]; then
	PHP="/opt/remi/php${NUM}/root/usr/bin/php"
	PKG="php${NUM}-php-usearch"
	INI="/etc/opt/remi/php${NUM}/php.d/40-usearch.ini"
	SO="/opt/remi/php${NUM}/root/usr/lib64/php/modules/usearch.so"
else
	PHP=php
	PKG=php-usearch
	INI=/etc/php.d/40-usearch.ini
	SO=/usr/lib64/php/modules/usearch.so
fi

check() {
	local label="$1"
	shift
	printf '  %-60s' "$label"
	if "$@" >/dev/null 2>&1; then echo PASS; else echo FAIL; exit 1; fi
}
# Negative checks capture the output first, so a crashing php cannot be mistaken for
# the absence of what is being checked.
# (`check` runs these inside `if`, where errexit is off, so a failing php is returned on
# explicitly, and a known core module proves php itself ran.)
loaded() { local out; out="$("$PHP" -m)" || return 1; grep -qx Core <<<"$out" && grep -qx usearch <<<"$out"; }
absent_module() { local out; out="$("$PHP" -m)" || return 1; grep -qx Core <<<"$out" && ! grep -qx usearch <<<"$out"; }
works() {
	# shellcheck disable=SC2016  # PHP variables, not shell ones
	"$PHP" -r '
		$idx = new Usearch\Index(["dimensions" => 3, "metric" => Usearch\Metric::L2sq]);
		$idx->reserve(4);
		$idx->add(10, [1.0, 0.0, 0.0]);
		$idx->add(20, [0.0, 1.0, 0.0]);
		$rows = $idx->search([1.0, 0.0, 0.0], 2);
		exit(count($rows) === 2 && $rows[0]["key"] === 10 ? 0 : 1);'
}

# Remi, as its own wizard documents it: the release package, then the PHP stream. This
# bootstrap only builds the test container (the release rpm is not signed with a key from
# it), so its download is not signature-checked; the PHP-version check below fails closed.
# shellcheck disable=SC1091
. /etc/os-release
dnf -y -q install ca-certificates cpio >/dev/null
if [ "$ID" = "fedora" ]; then
	remi_url="https://rpms.remirepo.net/fedora/remi-release-${VERSION_ID}.rpm"
else
	remi_url="https://rpms.remirepo.net/enterprise/remi-release-${VERSION_ID%%.*}.rpm"
fi
curl -fsSL -o /tmp/remi-release.rpm "$remi_url"
dnf -y -q install /tmp/remi-release.rpm >/dev/null

if [ "$LAYOUT" = "versioned" ]; then
	dnf -y -q install "php${NUM}-php-cli" >/dev/null
else
	if dnf -q module list php >/dev/null 2>&1; then
		dnf -y -q module reset php >/dev/null 2>&1
		dnf -y -q module enable "php:remi-${PHP_VER}" >/dev/null 2>&1
	else
		dnf -y -q install dnf-plugins-core >/dev/null 2>&1 || dnf -y -q install dnf5-plugins >/dev/null 2>&1 || true
		dnf config-manager setopt "remi-php${NUM}.enabled=1" >/dev/null 2>&1 \
			|| dnf config-manager --set-enabled "remi-php${NUM}" >/dev/null 2>&1
	fi
	dnf -y -q install php-cli >/dev/null
fi
installed_php="$("$PHP" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
[ "$installed_php" = "$PHP_VER" ] || { echo "FAIL: the container has PHP $installed_php, not $PHP_VER" >&2; exit 1; }

if [ "$EXPECT_REFUSE" = "1" ]; then
	echo "[a package for another PHP must be refused]"
	# Only a dependency refusal counts: a repository outage or a corrupt rpm also fails
	# dnf, and must not read as the package being correctly rejected.
	refused() {
		local out
		out="$(dnf -y install /pkg.rpm 2>&1)" && return 1
		grep -qE 'php[0-9]*\((api|zend-abi)\)' <<<"$out"
	}
	check "dnf refuses the install on the PHP ABI requirement" refused
	not_installed() { ! rpm -q "$(rpm -qp --queryformat '%{NAME}' /pkg.rpm)"; }
	check "the package is not installed" not_installed
	echo "RPM REFUSAL TEST PASSED"
	exit 0
fi

echo "[install]"
dnf -y -q install /pkg.rpm >/dev/null
check "rpm lists the package" rpm -q "$PKG"
check "extension loads in the CLI" loaded
check "add and search return the nearest key" works
check "ini is a config file" bash -c "rpm -qc $PKG | grep -qx $INI"
check "LICENSE and NOTICE are installed" bash -c "rpm -qL $PKG | grep -q LICENSE && rpm -qL $PKG | grep -q NOTICE"
rpm2cpio /pkg.rpm | cpio -i --to-stdout ".${SO}" 2>/dev/null | sha256sum | cut -d' ' -f1 > /tmp/shipped.sha
installed_sha() { [ "$(sha256sum "$SO" | cut -d' ' -f1)" = "$(cat /tmp/shipped.sha)" ]; }
check "installed module equals the packaged module" installed_sha
check "the signature-free package verifies its digests" rpm -K --nosignature /pkg.rpm
if [ -n "${MODULE_SHA256:-}" ]; then
	expected_sha() { [ "$(cat /tmp/shipped.sha)" = "$MODULE_SHA256" ]; }
	check "packaged module equals the EL8-built module" expected_sha
fi

echo "[upgrade to a newer version keeps the edit]"
printf '; local edit\n' >> "$INI"
# The newer package's ini differs from the old one (the test build adds a line), which is
# the only case where %config(noreplace) is distinguishable from a plain %config: the edit
# stays and the packaged file arrives as .rpmnew.
dnf -y -q install /newer.rpm >/dev/null
newer_is_higher() {
	local old new
	old="$(rpm -qp --qf '%{VERSION}' /pkg.rpm)"
	new="$(rpm -q --qf '%{VERSION}' "$PKG")"
	[ "$old" != "$new" ] && [ "$(printf '%s\n%s\n' "$old" "$new" | sort -V | tail -n1)" = "$new" ]
}
check "the newer, higher version is installed" newer_is_higher
check "edited ini survives the upgrade" grep -q 'local edit' "$INI"
check "the packaged ini arrives as .rpmnew" grep -q 'priority=40' "${INI}.rpmnew"
check "extension still loads" loaded

echo "[erase keeps the edited ini as .rpmsave]"
dnf -y -q remove "$PKG" >/dev/null
check "extension no longer loads" absent_module
check "edited ini is saved as .rpmsave" grep -q 'local edit' "${INI}.rpmsave"
check "module file removed" bash -c "! test -e $SO"

echo "[reinstall loads again]"
dnf -y -q install /pkg.rpm >/dev/null
check "extension loads again" loaded
dnf -y -q remove "$PKG" >/dev/null
rm -f "${INI}.rpmsave" "${INI}.rpmnew"
check "nothing left behind" bash -c "! test -e $INI && ! test -e $SO"
echo "RPM INSTALL TEST PASSED"

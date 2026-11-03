#!/usr/bin/env bash
# tools/deb/test.sh — runs inside a clean distro container (see tools/test-deb.sh).
#
# Copyright 2026 Fojle Rabbi (Rabib)
# SPDX-License-Identifier: Apache-2.0
#
# Environment: PHP_VER, PROVIDER (distro | sury | ondrej), EXPECT_REFUSE (0 | 1), and the
# package at /pkg.deb. With EXPECT_REFUSE=1 the package targets a different PHP than the
# one installed, and apt must refuse it; otherwise the full lifecycle is exercised.
set -euo pipefail

PHP_VER="${PHP_VER:?PHP_VER must be set}"
PROVIDER="${PROVIDER:-distro}"
EXPECT_REFUSE="${EXPECT_REFUSE:-0}"
export DEBIAN_FRONTEND=noninteractive

ini="/etc/php/${PHP_VER}/mods-available/usearch.ini"
link="/etc/php/${PHP_VER}/cli/conf.d/20-usearch.ini"
pkg="php${PHP_VER}-usearch"

check() {
	local label="$1"
	shift
	printf '  %-60s' "$label"
	if "$@" >/dev/null 2>&1; then echo PASS; else echo FAIL; exit 1; fi
}
# Negative checks capture the output first, so a crashing php or a failing find cannot
# be mistaken for the absence of what is being checked.
absent_module() { local out; out="$(php -m)"; ! grep -qx usearch <<<"$out"; }
loaded() { local out; out="$(php -m)"; grep -qx usearch <<<"$out"; }
nothing_left() { local out; out="$(find "$1" -name "$2")"; [ -z "$out" ]; }
works() {
	php -r '
		$idx = new Usearch\Index(["dimensions" => 3, "metric" => Usearch\Metric::L2sq]);
		$idx->reserve(4);
		$idx->add(10, [1.0, 0.0, 0.0]);
		$idx->add(20, [0.0, 1.0, 0.0]);
		$rows = $idx->search([1.0, 0.0, 0.0], 2);
		exit(count($rows) === 2 && $rows[0]["key"] === 10 ? 0 : 1);'
}

apt-get update -qq >/dev/null
case "$PROVIDER" in
	distro) ;;
	sury)
		apt-get install -y -qq --no-install-recommends curl ca-certificates >/dev/null
		curl -fsSL -o /tmp/sury-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
		dpkg -i /tmp/sury-keyring.deb >/dev/null
		# shellcheck disable=SC1091
		. /etc/os-release
		echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" \
			> /etc/apt/sources.list.d/php.list
		apt-get update -qq >/dev/null
		;;
	ondrej)
		apt-get install -y -qq --no-install-recommends software-properties-common ca-certificates >/dev/null
		# Launchpad's API occasionally stalls; bound each attempt and retry.
		added=0
		for attempt in 1 2 3; do
			if timeout 120 add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1; then added=1; break; fi
			echo "  PPA setup attempt $attempt did not finish; retrying" >&2
		done
		[ "$added" = "1" ] || { echo "FAIL: could not add ppa:ondrej/php" >&2; exit 1; }
		apt-get update -qq >/dev/null
		;;
	*) echo "FAIL: unknown provider '$PROVIDER'" >&2; exit 2 ;;
esac
apt-get install -y -qq --no-install-recommends "php${PHP_VER}-cli" >/dev/null

if [ "$EXPECT_REFUSE" = "1" ]; then
	echo "[a package for another PHP must be refused]"
	refused() { ! apt-get install -y -qq /pkg.deb; }
	check "apt refuses the install" refused
	not_installed() { ! dpkg -s "$(dpkg-deb -f /pkg.deb Package)"; }
	check "the package is not installed" not_installed
	echo "DEB REFUSAL TEST PASSED"
	exit 0
fi

echo "[install]"
apt-get install -y -qq /pkg.deb >/dev/null
check "dpkg lists the package" dpkg -s "$pkg"
check "extension loads in the CLI" loaded
check "add and search return the nearest key" works
check "ini is a conffile" grep -qx "$ini" "/var/lib/dpkg/info/${pkg}.conffiles"
check "CLI conf.d symlink exists" test -L "$link"
check "fpm reload trigger is registered" grep -q "fpm/conf.d" "/var/lib/dpkg/info/${pkg}.triggers"
dpkg-deb --fsys-tarfile /pkg.deb | tar -xO --wildcards './usr/lib/php/*/usearch.so' | sha256sum | cut -d' ' -f1 > /tmp/shipped.sha
installed_sha() { [ "$(sha256sum "$(php -r 'echo ini_get("extension_dir");')/usearch.so" | cut -d' ' -f1)" = "$(cat /tmp/shipped.sha)" ]; }
check "installed module equals the packaged module" installed_sha
if [ -n "${MODULE_SHA256:-}" ]; then
	expected_sha() { [ "$(cat /tmp/shipped.sha)" = "$MODULE_SHA256" ]; }
	check "packaged module equals the EL8-built module" expected_sha
fi

echo "[upgrade to a newer version keeps edits and enablement]"
printf '; local edit\n' >> "$ini"
mkdir /tmp/up
dpkg-deb -R /pkg.deb /tmp/up
control="$(cat /tmp/up/DEBIAN/control)"
old_version="$(dpkg-deb -f /pkg.deb Version)"
printf '%s\n' "${control/Version: $old_version/Version: ${old_version%-*}.1-1}" > /tmp/up/DEBIAN/control
dpkg-deb --root-owner-group -b /tmp/up /tmp/newer.deb >/dev/null
apt-get install -y -qq /tmp/newer.deb >/dev/null
check "upgrade installed the newer version" bash -c "dpkg -s $pkg | grep -q '^Version: .*\\.1-1'"
check "edited ini survives the upgrade" grep -q 'local edit' "$ini"
check "extension still loads" loaded

echo "[an administrator's phpdismod survives an upgrade]"
phpdismod -v "$PHP_VER" -s ALL usearch
check "extension is disabled" absent_module
apt-get install -y -qq --reinstall /tmp/newer.deb >/dev/null
check "stays disabled after a reinstall" absent_module
phpenmod -v "$PHP_VER" -s ALL usearch
check "phpenmod enables it again" loaded

echo "[edited ini survives remove]"
apt-get remove -y -qq "$pkg" >/dev/null
check "extension no longer loads" absent_module
check "edited ini is kept" grep -q 'local edit' "$ini"
check "CLI symlink is gone" bash -c "! test -e $link"

echo "[reinstall re-enables and keeps the edit]"
apt-get install -y -qq /pkg.deb >/dev/null
check "extension loads again" loaded
check "edited ini still present" grep -q 'local edit' "$ini"

echo "[purge leaves nothing]"
apt-get purge -y -qq "$pkg" >/dev/null
check "ini removed" bash -c "! test -e $ini"
check "no usearch file left under /etc/php" nothing_left /etc/php '*usearch*'
check "module file removed" nothing_left /usr/lib/php usearch.so
echo "DEB INSTALL TEST PASSED"

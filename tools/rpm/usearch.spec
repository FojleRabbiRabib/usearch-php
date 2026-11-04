# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 Fojle Rabbi (Rabib)
#
# Binary-only spec: it wraps the EL8-built module without recompiling it. One spec serves
# both Remi layouts, selected by --define "layout base|versioned". Defines passed by
# tools/rpm/build.sh: layout, phpver (8.3), abi (20230831), pkgversion, pkgrelease,
# changelog_date. The module's own library dependencies (the glibc symbol floor) are found
# by rpmbuild from the ELF, so nothing here restates them.

%global debug_package %{nil}
%global __strip /bin/true
%global __os_install_post %{nil}
%global _buildhost usearch-build
%global source_date_epoch_from_changelog 0
%global use_source_date_epoch_as_buildtime 1
%global clamp_mtime_to_source_date_epoch 1
%global _binary_payload w9.xzdio
%global phpnum %(echo %{phpver} | tr -d .)

%if "%{layout}" == "versioned"
%global pkgname php%{phpnum}-php-usearch
%global extdir /opt/remi/php%{phpnum}/root%{_libdir}/php/modules
%global inidir /etc/opt/remi/php%{phpnum}/php.d
%global phpreq php%{phpnum}-php
%global fpmunit php%{phpnum}-php-fpm
%else
%global pkgname php-usearch
%global extdir %{_libdir}/php/modules
%global inidir %{_sysconfdir}/php.d
%global phpreq php
%global fpmunit php-fpm
%endif

# The module is a plugin, not a library other packages link against.
%global __provides_exclude_from ^%{extdir}/.*\\.so$

Name:           %{pkgname}
Version:        %{pkgversion}
Release:        %{pkgrelease}
Summary:        In-process vector similarity search for PHP (USearch)
License:        Apache-2.0
URL:            https://github.com/FojleRabbiRabib/usearch-php
Source0:        usearch.so
Source1:        usearch.ini
Source2:        LICENSE
Source3:        NOTICE
Source4:        CHANGELOG.md

Requires:       %{phpreq}(api) = %{abi}-%{__isa_bits}
Requires:       %{phpreq}(zend-abi) = %{abi}-%{__isa_bits}
%if "%{layout}" == "versioned"
Requires:       php%{phpnum}-runtime
%endif

%description
A native PHP extension exposing the USearch HNSW vector index: add, search,
save, load and memory-map indexes in-process, with SIMD kernels selected at
run time. The C++ runtime is linked in statically; the module depends on the C
library only.

%prep
%setup -c -T
cp -p %{SOURCE2} %{SOURCE3} %{SOURCE4} .

%build

%install
install -D -m 0755 %{SOURCE0} %{buildroot}%{extdir}/usearch.so
install -D -m 0644 %{SOURCE1} %{buildroot}%{inidir}/40-usearch.ini

%posttrans
systemctl try-reload-or-restart %{fpmunit}.service >/dev/null 2>&1 || :

%postun
if [ $1 -eq 0 ]; then
    systemctl try-reload-or-restart %{fpmunit}.service >/dev/null 2>&1 || :
fi

%files
%defattr(-,root,root,-)
%license LICENSE NOTICE
%doc CHANGELOG.md
%{extdir}/usearch.so
%config(noreplace) %{inidir}/40-usearch.ini

%changelog
* %{changelog_date} Fojle Rabbi (Rabib) <fojle.rabbi.official@gmail.com> - %{version}-%{release}
- Packaged from the upstream release; see CHANGELOG.md for its changes.

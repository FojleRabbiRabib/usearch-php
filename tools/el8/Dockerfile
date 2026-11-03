# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 Fojle Rabbi (Rabib)
#
# Release build image: AlmaLinux 8 (glibc 2.28, the oldest glibc of every supported
# target) with a modern gcc-toolset and the Remi PHP that matches the packages.
# The base image is pinned by digest (a multi-architecture index) and the Remi release
# rpm by SHA-256; both are bumped deliberately. The toolset and PHP packages follow
# the repositories' current builds, so a bump of either is a reviewed change.
FROM almalinux:8@sha256:2d0f2eb2a83cc09dbc2eb92fade4dbe8f709628149e81aa00aec70630406603b

ARG PHP_VERSION=8.3
ARG GCC_TOOLSET=14
ARG REMI_RELEASE_SHA256=6381268fb5bb3864dcb5416089fdf169f564ba58d1fd350c855106785a5f740c

RUN set -eux; \
    dnf -y install curl ca-certificates; \
    curl -fsSL https://rpms.remirepo.net/enterprise/remi-release-8.rpm -o /tmp/remi-release-8.rpm; \
    echo "${REMI_RELEASE_SHA256}  /tmp/remi-release-8.rpm" | sha256sum -c -; \
    dnf -y install /tmp/remi-release-8.rpm; \
    rm /tmp/remi-release-8.rpm; \
    dnf -y module reset php; \
    dnf -y module enable "php:remi-${PHP_VERSION}"; \
    dnf -y install \
        "gcc-toolset-${GCC_TOOLSET}-gcc" "gcc-toolset-${GCC_TOOLSET}-gcc-c++" \
        autoconf automake libtool make binutils zip unzip git valgrind \
        php-cli php-devel php-process; \
    dnf clean all; \
    rm -rf /var/cache/dnf

ENV GCC_TOOLSET=${GCC_TOOLSET}

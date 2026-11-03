# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 Fojle Rabbi (Rabib)
#
# Debian packaging image: EL8 has no dpkg, so the .deb is assembled and linted here from
# the EL8-built module (see tools/build-deb.sh). The base image is pinned by digest and
# bumped deliberately, and it supplies dpkg, gzip and xz, which decide the package bytes;
# only lintian and binutils are added, so a rebuilt image cannot change those bytes.
FROM debian:bookworm@sha256:d74df7598108a173a621eda4d58a2adbf850b2d9a9d81bc2cca1a6c21174a880

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends lintian binutils; \
    rm -rf /var/lib/apt/lists/*

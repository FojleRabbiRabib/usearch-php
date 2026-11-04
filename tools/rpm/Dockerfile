# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 Fojle Rabbi (Rabib)
#
# RPM packaging image: the packages are assembled and linted on EL8 from the EL8-built
# module (see tools/build-rpm.sh), the oldest rpm every supported target must read, so a
# package built here is accepted everywhere. The base image is pinned by digest and bumped
# deliberately. The tools that decide the package bytes (rpm, rpm-build, xz, cpio) are pinned
# to exact builds and verified, so the same commit gives the same bytes in any rebuild of
# this image; if the repository drops a pinned build the image build fails, and bumping the
# pins is a deliberate change. rpmlint only judges, it does not shape the bytes.
FROM almalinux:8@sha256:2d0f2eb2a83cc09dbc2eb92fade4dbe8f709628149e81aa00aec70630406603b

RUN set -eux; \
    dnf -y install rpm-build-4.14.3-32.el8_10 rpmlint; \
    for pkg in rpm-4.14.3-32.el8_10 rpm-build-4.14.3-32.el8_10 xz-5.2.4-4.el8_6 cpio-2.12-11.el8; do \
        rpm -q "$pkg" >/dev/null; \
    done; \
    dnf clean all; \
    rm -rf /var/cache/dnf

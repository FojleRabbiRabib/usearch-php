# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 Fojle Rabbi (Rabib)
#
# RPM signing image: rpms are signed on EL8 so the signature is one every supported rpm
# accepts (EL8 ships rpm 4.14.3, the oldest reader). The base image is pinned by digest;
# rpm-sign is pinned to the same build as the rpm it drives, and gnupg2, which handles the
# key, to an exact build; both are verified. A pin the repository drops fails the image build
# (the tool-images workflow rebuilds them weekly to find that early).
FROM almalinux:8@sha256:2d0f2eb2a83cc09dbc2eb92fade4dbe8f709628149e81aa00aec70630406603b

RUN set -eux; \
    dnf -y install rpm-sign-4.14.3-32.el8_10 gnupg2-2.2.20-4.el8_10; \
    rpm -q rpm-4.14.3-32.el8_10 rpm-sign-4.14.3-32.el8_10 gnupg2-2.2.20-4.el8_10 >/dev/null; \
    dnf clean all; \
    rm -rf /var/cache/dnf

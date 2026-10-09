#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 4 || $1 != "--modsecurity-version" || $3 != "--apache-version" ]]; then
    echo "Usage: $0 --modsecurity-version <modsecurity_version> --apache-version <apache_version>"
    exit 1
fi
MODSEC_VERSION="$2"
APACHE_VERSION="$4"

MODSECDIR="$(pwd)/build/modsecurity-${MODSEC_VERSION}"
WORKDIR="${MODSECDIR}/connector-apache-${APACHE_VERSION}"
mkdir -p "$WORKDIR"

echo "Building ModSecurity connector for Apache $APACHE_VERSION"
echo "Artifacts will be in $WORKDIR"

docker run --rm \
    -e APACHE_VERSION="$APACHE_VERSION" \
    -v "$MODSECDIR/lib/libmodsecurity.so.$MODSEC_VERSION:/modsec/lib/libmodsecurity.so" \
    -v "$MODSECDIR/include:/modsec/include" \
    -v "$MODSECDIR/deps:/modsec/deps" \
    -v "$WORKDIR":/out \
    debian:11 bash -c '
        set -euo pipefail
        export DEBIAN_FRONTEND=noninteractive

        apt-get update
        apt-get -y install wget git build-essential libapr1-dev libaprutil1-dev libpcre3-dev libtool

        cd /tmp
        wget --https-only --max-redirect=0 "https://dlcdn.apache.org/httpd/httpd-${APACHE_VERSION}.tar.gz"
        tar xzf httpd-${APACHE_VERSION}.tar.gz
        cd httpd-${APACHE_VERSION}
        ./configure
        make
        make install

        cd /tmp
        git clone --depth=1 https://github.com/SpiderLabs/modsecurity-apache.git
        cd modsecurity-apache
        ./autogen.sh
        LD_LIBRARY_PATH=/modsec/deps ./configure --with-libmodsecurity=/modsec
        make

        strip --strip-unneeded src/.libs/mod_security3.so
        cp src/.libs/mod_security3.so /out/
    '

echo "Build finished!"

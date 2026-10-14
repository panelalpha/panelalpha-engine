#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 4 || $1 != "--modsecurity-version" || $3 != "--nginx-version" ]]; then
    echo "Usage: $0 --modsecurity-version <modsecurity_version> --nginx-version <nginx_version>"
    exit 1
fi
MODSEC_VERSION="$2"
NGINX_VERSION="$4"

MODSECDIR="$(pwd)/build/modsecurity-${MODSEC_VERSION}"
WORKDIR="${MODSECDIR}/connector-nginx-${NGINX_VERSION}"
mkdir -p "$WORKDIR"

echo "Building ModSecurity connector for Nginx $NGINX_VERSION"
echo "Artifacts will be in $WORKDIR"

docker run --rm \
    -e NGINX_VERSION="$NGINX_VERSION" \
    -v "$MODSECDIR/lib/libmodsecurity.so.$MODSEC_VERSION:/modsec/lib/libmodsecurity.so" \
    -v "$MODSECDIR/include:/modsec/include" \
    -v "$MODSECDIR/deps:/modsec/deps" \
    -v "$WORKDIR":/out \
    debian:11-slim bash -c '
        set -euo pipefail
        export DEBIAN_FRONTEND=noninteractive

        apt-get update
        apt-get -y install wget git g++ libpcre2-dev zlib1g-dev make
        
        cd /tmp
        wget --https-only --max-redirect=0 "https://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz"
        tar xzf "nginx-${NGINX_VERSION}.tar.gz"
        cd "nginx-${NGINX_VERSION}"

        git clone --depth=1 https://github.com/owasp-modsecurity/ModSecurity-nginx.git

        ./configure \
            --add-dynamic-module=./ModSecurity-nginx \
            --with-compat \
            --with-cc-opt="-I/modsec/include" \
            --with-ld-opt="-L/modsec/lib -lmodsecurity -Wl,-rpath,/modsec/lib -Wl,-rpath,/modsec/deps"
        make modules -j"$(nproc)"

        strip --strip-unneeded objs/ngx_http_modsecurity_module.so
        cp objs/ngx_http_modsecurity_module.so /out/
    '

echo "Build finished!"

# SOGo, built from this checkout.
#
# The repository is not self-contained: SOPE/ here holds only NGCards and
# GDLContentStore, the two subprojects SOGo owns. The rest of SOPE (core, xml,
# mime, appserver, gdl1, ldap) and libsbjson are separate products, and
# packaging/debian/control Build-Depends on them as libsope-*4.9-dev /
# libsbjson2.3-dev. Upstream publishes exactly those as bookworm packages, so
# this installs them from packages.sogo.nu and then builds the checkout itself.
# Nothing of SOGo comes prebuilt: the `sogo` binary package is never installed.
ARG SOGO_SUITE=bookworm

FROM debian:bookworm AS build
ARG SOGO_SUITE
ENV DEBIAN_FRONTEND=noninteractive
# The key SOGo's own documentation names, by fingerprint, from keys.openpgp.org.
# keyserver.ubuntu.com does not carry it and packages.sogo.nu serves no key file.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
 && curl -sSfL "https://keys.openpgp.org/vks/v1/by-fingerprint/74FFC6D72B925A34B5D356BDF8A27B36A6E2EAE9" \
      | gpg --dearmor -o /usr/share/keyrings/sogo.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/sogo.gpg] https://packages.sogo.nu/nightly/5/debian/ ${SOGO_SUITE} ${SOGO_SUITE}" \
      > /etc/apt/sources.list.d/sogo.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      make gobjc gnustep-make gnustep-base-common gnustep-base-runtime libgnustep-base-dev \
      libsope-appserver4.9-dev libsope-core4.9-dev libsope-gdl1-4.9-dev libsope-ldap4.9-dev \
      libsope-mime4.9-dev libsope-xml4.9-dev libsbjson2.3-dev \
      libldap2-dev libmemcached-dev libxml2-dev libssl-dev libcurl4-openssl-dev \
      libwbxml2-dev liblasso3-dev libytnef0-dev libzip-dev libsodium-dev \
 && rm -rf /var/lib/apt/lists/*

COPY . /usr/src/sogo
WORKDIR /usr/src/sogo
# `set -e` without `-u`: GNUstep.sh reads $ZSH_VERSION unguarded and dies under
# `set -u`. No pipes either -- /bin/sh here is dash, which has no pipefail, and
# a `make | tail` would report success for a failed compile.
RUN set -ex; \
    . /usr/share/GNUstep/Makefiles/GNUstep.sh; \
    ./configure --enable-strip > /tmp/configure.log 2>&1 || { tail -40 /tmp/configure.log; exit 1; }; \
    make -j"$(nproc)" > /tmp/make.log 2>&1 || { grep -n "Error\|error:" /tmp/make.log | tail -40; exit 1; }; \
    make install > /tmp/install.log 2>&1 || { tail -40 /tmp/install.log; exit 1; }; \
    test -x /usr/local/sbin/sogod; \
    test -d /usr/local/lib/GNUstep/SOGo/WebServerResources

FROM debian:bookworm-slim
ARG SOGO_SUITE
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
 && curl -sSfL "https://keys.openpgp.org/vks/v1/by-fingerprint/74FFC6D72B925A34B5D356BDF8A27B36A6E2EAE9" \
      | gpg --dearmor -o /usr/share/keyrings/sogo.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/sogo.gpg] https://packages.sogo.nu/nightly/5/debian/ ${SOGO_SUITE} ${SOGO_SUITE}" \
      > /etc/apt/sources.list.d/sogo.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      gnustep-base-common gnustep-base-runtime \
      libsope-appserver4.9 libsope-core4.9 libsope-gdl1-4.9 libsope-ldap4.9 \
      libsope-mime4.9 libsope-xml4.9 libsbjson2.3 \
      sope4.9-libxmlsaxdriver sope4.9-gdl1-mysql \
      libmemcached11 libytnef0 liblasso3 libzip4 libwbxml2-0 libsodium23 \
      nginx-light mariadb-client openssl procps tzdata \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default

# The three trees `make install` writes, and nothing else from the build image.
COPY --from=build /usr/local/lib/GNUstep /usr/local/lib/GNUstep
COPY --from=build /usr/local/lib/sogo /usr/local/lib/sogo
COPY --from=build /usr/local/sbin/sogod /usr/local/sbin/sogo-tool /usr/local/sbin/sogo-ealarms-notify /usr/local/sbin/

# libSOGo/libNGCards/libGDLContentStore live outside the default search path.
RUN echo /usr/local/lib/sogo > /etc/ld.so.conf.d/sogo.conf && ldconfig \
 && useradd --system --home-dir /var/lib/sogo --create-home --shell /usr/sbin/nologin sogo \
 && mkdir -p /etc/sogo /var/log/sogo /var/run/sogo /var/spool/sogo \
 && chown sogo:sogo /var/log/sogo /var/run/sogo /var/spool/sogo /etc/sogo \
 && chmod 750 /etc/sogo

COPY panelalpha/nginx-sogo.conf /etc/nginx/conf.d/sogo.conf
COPY panelalpha/entrypoint.sh /panelalpha-sogo-entrypoint.sh
COPY panelalpha/sogo-users.sh /usr/local/bin/sogo-users
RUN chmod 755 /panelalpha-sogo-entrypoint.sh /usr/local/bin/sogo-users

EXPOSE 80
CMD ["/panelalpha-sogo-entrypoint.sh"]

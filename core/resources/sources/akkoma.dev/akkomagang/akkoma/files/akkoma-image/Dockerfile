# Thin packaging wrapper around Akkoma's OFFICIAL prebuilt OTP release. It
# COMPILES NOTHING and repairs no build: the release is the artifact upstream's
# own CI (.woodpecker/publish.yml) builds and publishes for every branch and
# tag, and the zip bundles its own ERTS, so only Akkoma's documented runtime
# system deps are installed. This exists because Akkoma ships no maintained
# container image and its from-source docker-compose is a local-dev template the
# engine will not select (build: + project-root bind = isLocalDevCompose).
FROM debian:bookworm-slim

# Akkoma's documented OTP runtime dependencies (docs/installation/otp_en.md):
# libncurses (BEAM), libssl/ca-certificates (TLS + federation), imagemagick +
# ffmpeg + exiftool (media processing), libmagic (file type detection),
# postgresql-client (pg_isready + the seed's psql gate), curl/unzip (fetch the
# release + healthcheck).
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl unzip \
        libncurses6 libstdc++6 libssl3 libmagic1 \
        imagemagick ffmpeg libimage-exiftool-perl \
        postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Non-root runtime user. uid 1000 also owns the fresh /var/lib/akkoma named
# volume (Docker seeds a new named volume from the image path, ownership and
# all), so uploads/secret/frontends are writable with no chown at runtime.
RUN groupadd -g 1000 akkoma && useradd -u 1000 -g 1000 -m -d /opt/akkoma akkoma

# The official prebuilt release. Pinned to v3.20.0 (== current `stable`,
# 2026-08-08); override the whole URL to move version or branch.
ARG AKKOMA_RELEASE_URL=https://akkoma-updates.s3-website.fr-par.scw.cloud/v3.20.0/akkoma-amd64.zip
RUN curl -fsSL "$AKKOMA_RELEASE_URL" -o /tmp/akkoma.zip \
    && unzip -q /tmp/akkoma.zip -d /tmp/akkoma-rel \
    && cp -a /tmp/akkoma-rel/release/. /opt/akkoma/ \
    && rm -rf /tmp/akkoma.zip /tmp/akkoma-rel \
    && chown -R akkoma:akkoma /opt/akkoma

# /var/lib/akkoma holds the generated secret.exs, uploaded media and the
# installed frontends -- all on the akkoma_var named volume at runtime.
RUN mkdir -p /var/lib/akkoma/uploads /var/lib/akkoma/static \
    && chown -R akkoma:akkoma /var/lib/akkoma

# Akkoma's OWN config, shipped verbatim (only registrations_open made
# env-driven). The release loads it at BOOT via ReleaseRuntimeProvider, so every
# System.get_env in it reads the account's real DOMAIN/DB at runtime. The
# provider REFUSES a world-readable config, so it is 0640 owned by akkoma.
RUN mkdir -p /etc/akkoma
COPY config.exs /etc/akkoma/config.exs
RUN chown -R akkoma:akkoma /etc/akkoma && chmod 750 /etc/akkoma && chmod 640 /etc/akkoma/config.exs

COPY entrypoint.sh /usr/local/bin/pa-entrypoint.sh
COPY init.sh /usr/local/bin/pa-init.sh
RUN chmod 0755 /usr/local/bin/pa-entrypoint.sh /usr/local/bin/pa-init.sh

# ERL_EPMD_ADDRESS keeps epmd on loopback (matches upstream's Dockerfile); the
# release's env.sh defaults RELEASE_DISTRIBUTION=name, node pleroma@127.0.0.1.
# C.UTF-8 + "+fnu" stop the BEAM from running with latin1 name encoding (debian
# -slim ships no locale), which Elixir warns will make it malfunction.
ENV AKKOMA_CONFIG_PATH=/etc/akkoma/config.exs \
    ERL_EPMD_ADDRESS=127.0.0.1 \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    ELIXIR_ERL_OPTIONS=+fnu

WORKDIR /opt/akkoma
USER akkoma
EXPOSE 4000
ENTRYPOINT ["/usr/local/bin/pa-entrypoint.sh"]

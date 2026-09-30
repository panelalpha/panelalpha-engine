# Upstream's "Install from system packages" for Debian 13: `apt install mopidy`
# from the Mopidy APT archive (docs/installation/packages.md). The package
# creates the mopidy system user with its home in /var/lib/mopidy.
FROM debian:trixie-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates wget \
 && mkdir -p /etc/apt/keyrings \
 && wget -q -O /etc/apt/keyrings/mopidy-archive-keyring.gpg https://apt.mopidy.com/mopidy-archive-keyring.gpg \
 && wget -q -O /etc/apt/sources.list.d/mopidy.sources https://apt.mopidy.com/trixie.sources \
 && apt-get update \
 && apt-get install -y mopidy \
 && rm -rf /var/lib/apt/lists/* \
 && install -d -o mopidy -g audio /var/lib/mopidy/Music
USER mopidy
ENV HOME=/var/lib/mopidy
WORKDIR /var/lib/mopidy
EXPOSE 6680
# Mopidy-HTTP binds 127.0.0.1 by default.
CMD ["mopidy", "-o", "http/hostname=0.0.0.0"]

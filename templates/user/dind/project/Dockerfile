# Use bookworm as base for DinD container
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

# Update os and install requirements
RUN apt-get update && \
  apt-get install -y \
  lsb-release \
  apt-transport-https \
  ca-certificates \
  wget \
  gnupg \
  curl \
  git \
  inotify-tools \
  cron \
  zip \
  mariadb-client \
  rsync

# Framework base images (node, nginx, php, composer) are NOT baked
# in here. The inner daemon's data-root is ~/docker (see daemon.json in
# getEntrypointInitScripts), so layers in this image's /var/lib/docker would
# never be used. Preload is DindImageSeed after DinD up and
# Dind::preloadFrameworkBaseImages() before compose up.

# Install Docker CE
RUN curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg && \
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null && \
  apt-get update && \
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Install Railpack
RUN curl -fsSL https://raw.githubusercontent.com/railwayapp/railpack/main/install.sh | bash -s -- --yes

# cloudflared (Cloudflare Tunnel connector) — one process per DinD account
RUN ARCH="$(dpkg --print-architecture)" \
  && case "$ARCH" in \
       amd64) CF_ARCH=amd64 ;; \
       arm64) CF_ARCH=arm64 ;; \
       *) echo "unsupported arch: $ARCH" >&2; exit 1 ;; \
     esac \
  && curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${CF_ARCH}" \
       -o /usr/local/bin/cloudflared \
  && chmod +x /usr/local/bin/cloudflared \
  && cloudflared --version

# Create the log file to be able to run tail
RUN mkdir -p /var/log/cron

RUN apt-get install -y msmtp s6
RUN ln -sf /usr/bin/msmtp /usr/sbin/sendmail

# PHP + WP-CLI
RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg && \
  echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" >/etc/apt/sources.list.d/php.list
RUN apt-get update && apt-get install -y \
  php8.3 \
  php8.3-cli \
  php8.3-fpm \
  php8.3-common \
  php8.3-bcmath \
  php8.3-bz2 \
  php8.3-common \
  php8.3-curl \
  php8.3-dom \
  php8.3-gd \
  php8.3-imap \
  php8.3-intl \
  php8.3-ldap \
  php8.3-mbstring \
  php8.3-mysql \
  php8.3-opcache \
  php8.3-sqlite3 \
  php8.3-tokenizer \
  php8.3-xml \
  php8.3-zip \
  php8.3-redis \
  php8.3-imagick \
  php8.3-apcu \
  php8.3-soap
COPY ./wp-cli/wp-cli.phar /opt/wp-cli.phar

# Clear apt
RUN rm -rf /var/lib/apt/lists/* && rm -rf /var/cache/apk/*

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]

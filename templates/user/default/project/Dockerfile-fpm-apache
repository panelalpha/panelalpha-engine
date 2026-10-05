# Use bookworm as base
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

# Update os and install requirements
RUN apt-get update && \
  apt-get install -y \
  lsb-release \
  apt-transport-https \
  ca-certificates \
  wget \
  curl \
  git \
  gnupg \
  inotify-tools \
  cron \
  zip \
  mariadb-client \
  rsync

# Install redis-server
RUN apt-get install -y \
  redis-server

# Disable redis logs
RUN sed -i 's/^logfile/#&/' /etc/redis/redis.conf

# Create the log file to be able to run tail
RUN mkdir -p /var/log/cron

# Add repo PHP 8.X from Ondřeja Surý
RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg && \
  echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" >/etc/apt/sources.list.d/php.list

# Update repo and install apache2
RUN apt-get update && \
  apt-get install -y \
  apache2 \
  libapache2-mod-fcgid

RUN apt-get install -y \
  php7.4 \
  php7.4-cli \
  php7.4-fpm \
  php7.4-common \
  php7.4-bcmath \
  php7.4-bz2 \
  php7.4-common \
  php7.4-curl \
  php7.4-dom \
  php7.4-gd \
  php7.4-imap \
  php7.4-intl \
  php7.4-ldap \
  php7.4-mbstring \
  php7.4-mysql \
  php7.4-opcache \
  php7.4-sqlite3 \
  php7.4-tokenizer \
  php7.4-xml \
  php7.4-zip \
  php7.4-redis \
  php7.4-imagick \
  php7.4-apcu \
  php7.4-soap

RUN apt-get install -y \
  php8.0 \
  php8.0-cli \
  php8.0-fpm \
  php8.0-common \
  php8.0-bcmath \
  php8.0-bz2 \
  php8.0-common \
  php8.0-curl \
  php8.0-dom \
  php8.0-gd \
  php8.0-imap \
  php8.0-intl \
  php8.0-ldap \
  php8.0-mbstring \
  php8.0-mysql \
  php8.0-opcache \
  php8.0-sqlite3 \
  php8.0-tokenizer \
  php8.0-xml \
  php8.0-zip \
  php8.0-redis \
  php8.0-imagick \
  php8.0-apcu \
  php8.0-soap

RUN apt-get install -y \
  php8.1 \
  php8.1-cli \
  php8.1-fpm \
  php8.1-common \
  php8.1-bcmath \
  php8.1-bz2 \
  php8.1-common \
  php8.1-curl \
  php8.1-dom \
  php8.1-gd \
  php8.1-imap \
  php8.1-intl \
  php8.1-ldap \
  php8.1-mbstring \
  php8.1-mysql \
  php8.1-opcache \
  php8.1-sqlite3 \
  php8.1-tokenizer \
  php8.1-xml \
  php8.1-zip \
  php8.1-redis \
  php8.1-imagick \
  php8.1-apcu \
  php8.1-soap

RUN apt-get install -y \
  php8.2 \
  php8.2-cli \
  php8.2-fpm \
  php8.2-common \
  php8.2-bcmath \
  php8.2-bz2 \
  php8.2-common \
  php8.2-curl \
  php8.2-dom \
  php8.2-gd \
  php8.2-imap \
  php8.2-intl \
  php8.2-ldap \
  php8.2-mbstring \
  php8.2-mysql \
  php8.2-opcache \
  php8.2-sqlite3 \
  php8.2-tokenizer \
  php8.2-xml \
  php8.2-zip \
  php8.2-redis \
  php8.2-imagick \
  php8.2-apcu \
  php8.2-soap

RUN apt-get install -y \
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

RUN apt-get install -y \
  php8.4 \
  php8.4-cli \
  php8.4-fpm \
  php8.4-common \
  php8.4-bcmath \
  php8.4-bz2 \
  php8.4-common \
  php8.4-curl \
  php8.4-dom \
  php8.4-gd \
  php8.4-imap \
  php8.4-intl \
  php8.4-ldap \
  php8.4-mbstring \
  php8.4-mysql \
  php8.4-opcache \
  php8.4-sqlite3 \
  php8.4-tokenizer \
  php8.4-xml \
  php8.4-zip \
  php8.4-redis \
  php8.4-imagick \
  php8.4-apcu \
  php8.4-soap

RUN apt-get install -y msmtp s6
RUN ln -sf /usr/bin/msmtp /usr/sbin/sendmail

# Clear apt
RUN rm -rf /var/lib/apt/lists/* && rm -rf /var/cache/apk/*

# Add mod for php-fpm and ssl
RUN a2enmod actions fcgid alias proxy_fcgi setenvif ssl rewrite

# Set ServerName
RUN echo "ServerName localhost" >>/etc/apache2/apache2.conf

# add wpcli phar
COPY ./wp-cli/wp-cli.phar /opt/wp-cli.phar

# Set workdir
WORKDIR /var/www

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]

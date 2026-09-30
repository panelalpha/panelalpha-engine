#!/bin/sh
# Writes /etc/libretime/config.yml from upstream's docker config template
# (4.5.0 docker/config.template.yml) with this account's URL and secrets.
set -e
cat > /etc/libretime/config.yml <<EOT
general:
  public_url: ${PA_PUBLIC_URL}
  api_key: ${LT_API_KEY}
  secret_key: ${LT_SECRET_KEY}
  allowed_cors_origins: []
  timezone: ${LT_TIMEZONE:-UTC}
  cache_ahead_hours: 1
  auth: local
storage:
  path: /srv/libretime
database:
  host: postgres
  port: 5432
  name: libretime
  user: libretime
  password: ${POSTGRES_PASSWORD}
rabbitmq:
  host: rabbitmq
  port: 5672
  vhost: /libretime
  user: libretime
  password: ${RABBITMQ_DEFAULT_PASS}
email:
  from_address: no-reply@libretime.org
  host: localhost
  port: 25
playout:
  liquidsoap_host: liquidsoap
  liquidsoap_port: 1234
liquidsoap:
  server_listen_address: 0.0.0.0
  server_listen_port: 1234
  harbor_listen_address: ["0.0.0.0"]
stream:
  inputs:
    main:
      mount: main
      port: 8001
    show:
      mount: show
      port: 8002
  outputs:
    icecast:
      - enabled: true
        host: icecast
        port: 8000
        mount: main
        source_password: ${ICECAST_SOURCE_PASSWORD}
        admin_password: ${ICECAST_ADMIN_PASSWORD}
        name: LibreTime!
        description: LibreTime Radio!
        website: https://libretime.org
        genre: various
        audio:
          format: ogg
          bitrate: 256
EOT
chmod 644 /etc/libretime/config.yml
echo "config.yml written for ${PA_PUBLIC_URL}"

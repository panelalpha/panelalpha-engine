#!/bin/sh
# CyTube reads only /app/config.yaml. Write it from the account's database,
# address and per-account secret into /tmp (not ~/project), then start.
set -e
mkdir -p /tmp/cytube
umask 077
cat > /tmp/cytube/config.yaml <<YAML
mysql:
  server: '${DB_HOST}'
  port: ${DB_PORT:-3306}
  database: '${DB_DATABASE}'
  user: '${DB_USERNAME}'
  password: '${DB_PASSWORD}'
  pool-size: 10
# One listener for pages and socket.io: the account publishes one port.
listen:
  - ip: '0.0.0.0'
    port: 3000
    http: true
    io: true
    url: '${APP_URL}'
http:
  default-port: 3000
  root-domain: '${SERVERNAME}'
  alt-domains: []
  cookie-secret: '${PA_INSTANCE_SECRET}'
  # The engine's proxy hops are private addresses; the visitor is the last public one.
  trust-proxies: ['loopback', 'uniquelocal']
io:
  domain: '${APP_URL}'
  default-port: 3000
YAML
ln -sfn /tmp/cytube/config.yaml /app/config.yaml
exec node index.js

# Graylog (github.com/Graylog2/graylog2-server)

Log management: web UI and REST API on :9000.

## Deploying

No variables are required. Give the project about 4 GB of memory (Graylog 1 GB
heap, OpenSearch 768 MB heap, MongoDB). Sign in as `admin`; the password is
generated on the first deploy:

    cat ~/.panelalpha/graylog/admin-password

To change it, write a new password there and replace `GRAYLOG_ROOT_PASSWORD_SHA2`
in `~/.panelalpha/graylog/graylog.env` with its SHA-256, then redeploy.

## What the recipe does

- `overrides/docker-compose.yml` runs `graylog/graylog:7.1.9` (current stable),
  `mongo:7.0` and `opensearchproject/opensearch:2.19.6` (single node, security
  plugin off, not published). Each keeps its data on a named volume.
- `hooks/prepare.sh` generates `password_secret` and the admin password once
  into `~/.panelalpha/graylog/` (0600 files in a 0700 dir), passed as an
  `env_file`. Graylog refuses to start without them.
- `ready` makes `compose up -d` wait until `/api/system/lbstatus` answers.

## Limitation

Only the web port reaches the account. Inputs that listen on their own ports
(Syslog, GELF TCP/UDP/HTTP, Beats) run but are reachable only from inside the
account; pull-type inputs work as usual.

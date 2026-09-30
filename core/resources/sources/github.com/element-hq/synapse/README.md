# Synapse (github.com/element-hq/synapse)

Reference Matrix homeserver, run from upstream's release image.

## What the recipe does

- `overrides/docker-compose.yml` runs `matrixdotorg/synapse:v1.162.0` on port
  8008 with `/data` on the named volume `data`.
- `generate` (same image, one-shot) runs the image's `generate` mode with
  `SYNAPSE_SERVER_NAME=${PA_PUBLIC_HOST}`: first boot writes
  `homeserver.yaml`, the signing key and the log config; later deploys print
  "Config file '/data/homeserver.yaml' already exists" and change nothing.
- The generated config uses SQLite; database and media store sit on the same
  volume. `ready` makes `compose up -d` wait for `/health`.

The server name is permanent (it is part of every user id): it is the account's
domain at the first deploy.

## First run

Registration is closed, as upstream ships it. Create users with
`docker compose -p project exec app register_new_matrix_user -c /data/homeserver.yaml http://127.0.0.1:8008`.
Edit `/data/homeserver.yaml` on the volume for Postgres, registration or
federation delegation.

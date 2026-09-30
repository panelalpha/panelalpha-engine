# Vault (github.com/hashicorp/vault)

Secrets management; web UI and HTTP API on :8200.

## What the recipe does

- `overrides/docker-compose.yml` runs `hashicorp/vault:2.1.1` in server mode
  (not `-dev`), configured through `VAULT_LOCAL_CONFIG`: file storage in
  `/vault/file` on the named volume `data`, UI on, TLS off (the engine's proxy
  terminates HTTPS), `disable_mlock` (no IPC_LOCK in the account).
- `VAULT_API_ADDR` is the site's public URL.
- A no-op `ready` service holds `compose up` until `/v1/sys/health` answers.

## After deploying

Open the site and initialize Vault (Vault's own first-run page); keep the
unseal keys and root token it shows. Vault starts sealed after every restart
or redeploy; unseal it from the UI with those keys. Data on the volume survives
redeploys.

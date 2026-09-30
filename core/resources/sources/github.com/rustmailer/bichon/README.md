# Bichon (github.com/rustmailer/bichon)

Email archiving server (Rust): pulls mail from IMAP accounts, indexes it with
Tantivy and serves a web UI and REST API. No external database.

## Deploying

Nothing to set. Open the site and log in with upstream's built-in account,
`admin` / `admin@bichon`; upstream says to change it at once under
Settings -> Profile. The embedded SMTP receiver stays off (upstream default).

## What the recipe does

- `overrides/docker-compose.yml` runs `rustmailer/bichon:2.0.3` on port 15630
  with `/data` (metadata DB, index, mail blobs) on the named volume
  `bichon-data`, kept across redeploys. `ready` makes `compose up -d` wait for
  `/api/status`. The repository's `docker/Dockerfile` copies CI-built binaries,
  and a plain `cargo build` needs pnpm for the web UI (`crates/server/build.rs`).
- `hooks/prepare.sh` generates `BICHON_ENCRYPT_PASSWORD` once into
  `~/.panelalpha/bichon/app.env` (0600). The server refuses to start without
  it, and it encrypts stored IMAP passwords and OAuth tokens, so it must never
  change: a new value makes every stored credential unreadable.

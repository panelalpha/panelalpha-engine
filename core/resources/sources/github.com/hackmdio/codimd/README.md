# CodiMD (github.com/hackmdio/codimd)

Real-time collaborative markdown notes (Node.js, PostgreSQL).

## Deploying

Nothing to set. Open the site; sign-up by email and anonymous notes are on, as
upstream ships them (`CMD_ALLOW_EMAIL_REGISTER`, `CMD_ALLOW_ANONYMOUS` and the
other `CMD_*` settings can be set as project environment variables).

## What the recipe does

- `overrides/docker-compose.yml` runs `hackmdio/hackmd:2.6.1` on port 3000 with
  `postgres:16-alpine`, the stack of upstream's `deployments/docker-compose.yml`;
  the database and `public/uploads` are on named volumes, kept across redeploys.
  The image runs `sequelize db:migrate` on every start.
- `hooks/prepare.sh` writes the database password and `CMD_SESSION_SECRET` once
  to `~/.panelalpha/codimd/` (0600 files in a 0700 dir).
- `CMD_DOMAIN` is the account's domain, with `CMD_PROTOCOL_USESSL=true`.
- `ready` makes `compose up -d` wait for `/status`.
- The repository is not built: `npm install` fails in `phantomjs-prebuilt`,
  whose install script needs `bzip2`.

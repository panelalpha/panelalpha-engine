# The Lounge (github.com/thelounge/thelounge)

Web IRC client that stays connected (Node.js server, Vue client).

## Deploying

Deploy as is, open the site and sign in with the login the engine generates:
`THELOUNGE_USERNAME` (default `admin`) and `THELOUNGE_PASSWORD`, read with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- The express platform builds the repository as before (`yarn install
  --frozen-lockfile`, spelled out because a recipe does not resolve
  `{{js.install}}`, then the inherited `npm run build`), and The Lounge
  listens on its own port 9000.
- The Lounge is private by default: the sign-in page is the only way in and
  there is no sign-up; users are made only with the server's `thelounge add`.
- `files/panelalpha-thelounge-start.sh` is the start command. While
  `$THELOUNGE_HOME/users` has no user at all it runs upstream's
  `node index add <THELOUNGE_USERNAME> --password <THELOUNGE_PASSWORD>
  --save-logs`, then execs `node index start`, the repository's start script.
  A password changed in The Lounge (its settings, or `thelounge reset`) is
  never overwritten, and a deploy whose users were all replaced gets no admin
  back.
- `THELOUNGE_HOME` (config.js, users, chat logs, uploads) is
  `~/.panelalpha/thelounge`, created by `hooks/prepare.sh` so it is the
  account's, bind-mounted at `/pa-data/thelounge` by
  `overrides/docker-compose.override.yml`, which also gives the service the
  engine's login as `env_file`. Before, the home was inside the container and
  a redeploy lost every user and setting; this is the one change beyond the
  login.

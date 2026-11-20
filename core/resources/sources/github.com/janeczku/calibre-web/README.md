# Calibre-Web (github.com/janeczku/calibre-web)

Browse, read and download e-books from a Calibre library: a Flask app served
by gevent on :8083, settings in `/config/app.db`, the library in `/books`.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`CALIBRE_WEB_ADMIN_USER`, default `admin`, and `CALIBRE_WEB_ADMIN_PASSWORD`):
read it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).
Calibre-Web then asks for the library's location: `/books`.

## What the recipe does

- `overrides/docker-compose.yml` runs `linuxserver/calibre-web:0.6.27` with
  `/config` and `/books` on named volumes. `library-init` copies the
  repository's empty Calibre library (`library/metadata.db`) into `/books`
  once. A small nginx `web` front is the only published port (see
  `files/panelalpha/front.conf`: the engine's vhost sends `Connection:
  upgrade` on every request and gevent then never finishes a POST).
- The engine's login reaches the app service as `env_file`. Its entrypoint,
  `files/panelalpha/start.sh`, installs `files/panelalpha/calibre-web-login.sh`
  into `/custom-cont-init.d` (LinuxServer's hook for custom init scripts; it
  has to be executable and `files/` are copied without modes), then execs the
  image's `/init`.
- That script runs after the image has created `/config/app.db` (with the
  built-in `admin` / `admin123`) and before Calibre-Web starts. While the
  admin still has `admin123`, it renames the admin to the engine's username
  in `app.db` and sets the engine's password with Calibre-Web's own
  `cps.py -s user:password`. Otherwise it does nothing, so a password changed
  in Calibre-Web is never overwritten.
- The password is generated with a symbol: Calibre-Web's default password
  policy wants a digit, a lower and an upper case letter and a special
  character.

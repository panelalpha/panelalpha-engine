# DOMjudge (github.com/domjudge/domjudge)

Programming-contest jury system: the domserver serves the jury, team and
public scoreboard interfaces and the API (`domjudge/domserver`, MariaDB).

## Deploying

No project environment variables are needed. After the first deploy, the
domserver log holds the line `Initial admin password is ...` (the password is
also in `/opt/domjudge/domserver/etc/initial_admin_password.secret` inside the
container). Log in at `/login` as `admin` with it and change it under the jury
interface's user settings. A later redeploy prints `[unknown]` there, because
the password already lives in the database.

## What the recipe does

- `overrides/docker-compose.yml` runs `domjudge/domserver:9.0.0` on port 80 and
  `mariadb:11.4` with upstream's `--max-connections=1000` and
  `--max-allowed-packet=512M`. The database is the named volume `db`, so
  contests, teams and submissions are kept across redeploys.
- `hooks/prepare.sh` generates `MYSQL_PASSWORD` and `MYSQL_ROOT_PASSWORD` once
  into `~/.panelalpha/domjudge/db.env` (0600). The domserver uses the root
  password to install and upgrade its schema.
- `ready` makes `compose up -d` wait until the image's own health check passes.

## Not included: a judgehost

Judging needs `domjudge/judgehost`, which runs submissions under cgroups and
must be privileged. It is not started here, so submissions stay "queued".
Attach a judgehost running elsewhere with `DOMSERVER_BASEURL` set to this
site's address and the `judgehost` user's password from the jury interface.

The repository's own compose is DOMjudge's contributor environment and is not
used.

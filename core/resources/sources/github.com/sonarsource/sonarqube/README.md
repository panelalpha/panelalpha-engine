# SonarQube (github.com/SonarSource/sonarqube)

SonarQube Community Build, the static-analysis server (web server, compute
engine and embedded Elasticsearch on port 9000), on PostgreSQL.

## Deploying

Deploy as is, open `/sessions/new` and sign in with the login the engine
generates (`SONARQUBE_ADMIN_USERNAME`, default `admin`, and
`SONARQUBE_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` runs `sonarqube:26.9.0.129388-community`
  with `postgres:17-alpine` (the database password is the engine's
  `SONAR_DB_PASSWORD`), data, extensions and logs on named volumes, and
  upstream's documented Elasticsearch ulimits. `ready` makes the deploy finish
  only once `/api/system/status` reports UP and the login below is set.
- The login: a new database gets the built-in `admin` / `admin`, which the
  first sign-in only asks to change. The one-shot `admin-login` (the same
  image, for its bash and curl) runs `files/panelalpha/sonarqube/set-admin-login.sh`
  once SonarQube is UP: while `admin` / `admin` still authenticates it renames
  the admin when the engine's username is not `admin`
  (`api/users/update_login`) and changes its password as the admin itself
  (`api/users/change_password`), which also clears the "change your password"
  request. A password changed in SonarQube is never overwritten: once
  `admin` no longer works, the step does nothing. A failure there leaves
  `admin` / `admin` and says so in its log; it never fails the deploy.
- `symbol: true`: SonarQube's password policy wants an upper and a lower case
  letter, a digit and a special character.
- Until `admin-login` has run (a few seconds after SonarQube reports UP, on the
  first deploy only) the default login still works.

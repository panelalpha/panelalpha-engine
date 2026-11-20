# Rundeck (github.com/rundeck/rundeck)

Runbook automation and job scheduler (Grails on Java), its web console on port
4440, on PostgreSQL.

## Deploying

Deploy as is, open `/user/login` and sign in with the login the engine
generates (`RUNDECK_ADMIN_USERNAME`, default `admin`, and
`RUNDECK_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's image `rundeck/rundeck:6.2.1`
  on `postgres:17-alpine` (the repository is the Gradle source tree with no
  compose file), with the engine's login as `env_file` and the wrapper below as
  the entrypoint.
- `files/panelalpha-rundeck-login.sh` edits
  `/home/rundeck/server/config/realm.properties` while it still has the
  image's `admin:admin,user,admin` line: that line becomes the engine's
  username with `MD5:<md5 of the password>` and the same roles (`user,admin`,
  which `admin.aclpolicy` gives full access), and the image's second login
  `user:user,user` is removed. Then it unsets the two variables and execs the
  image's own `docker-lib/entry.sh` (under tini).
- `realm.properties` is in the image, not on a volume: every new container
  (each redeploy) starts from the shipped file and gets the same stored login
  again. Rundeck has no page to change a `realm.properties` password; a file
  edited by hand inside the container (no `admin:admin` line left) is never
  touched.

# XXL-JOB (github.com/xuxueli/xxl-job)

Distributed task scheduler: `xxl-job-admin` is the web console (Spring Boot,
port 8080) on MySQL; executors (the `xxl-job-executor-samples`, or your own
apps) register with it and run the jobs.

## Deploying

Deploy as is, open `/auth/login` and sign in with the login the engine
generates (`XXL_JOB_ADMIN_USERNAME`, default `admin`, and
`XXL_JOB_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). The console's language is upstream's default,
`zh_CN` (`xxl.job.i18n`).

## What the recipe does

Without it the java strategy builds the Maven project and runs xxl-job-admin
with no database, so the login page renders and no login works.
`overrides/docker-compose.yml` runs what upstream's `docker/docker-compose.yml`
describes instead:

- `db`: `mysql:8.4` with upstream's utf8mb4 settings, sized for a shared
  account, the data on the named volume `db` (kept across redeploys). On a new
  database it runs `doc/db/tables_xxl_job.sql` (schema, the sample executor
  groups and jobs, and the admin `admin` / `123456`), then
  `files/panelalpha-xxl-job-login.sh`, which sets the engine's username and
  password on that admin while its password is still the default. XXL-JOB keeps
  an unsalted SHA-256 of the password, which is what MySQL's `SHA2(..., 256)`
  gives. A password changed in XXL-JOB is never overwritten: the script runs
  only when the database is created.
- `app`: xxl-job-admin built by `files/panelalpha-xxl-job-admin.Dockerfile`.
  Upstream's `xxl-job-admin/Dockerfile` copies a jar built beforehand, which a
  clone does not have, so this one builds it from the checkout
  (`mvn -pl xxl-job-admin -am package`) and then runs it the way upstream's
  does (`eclipse-temurin:17-jre`, `TZ=PRC`, `JAVA_OPTS`, `PARAMS`). The
  datasource comes from `SPRING_DATASOURCE_*`, which Spring Boot reads over
  `application.properties`.
- `ready` makes `compose up` wait until `/actuator/health` is UP, which
  includes the database.
- `hooks/prepare.sh` generates the MySQL passwords once into
  `~/.panelalpha/xxl-job/` (`db.env` for MySQL, `app.env` for Spring).

## Not configured

The sample executor is not run. The seeded executor groups keep upstream's
access token `default_token`; set your own in the console before you register
executors from outside.

# Apache Airflow (github.com/apache/airflow)

Workflow scheduling and monitoring, Python.

Plain deploy: the repository is Airflow's source tree; the engine builds its
root `Dockerfile`, which compiles the production image from scratch and would
still need a database, a command and an admin to serve anything.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's
  `airflow-core/docs/howto/docker-compose/docker-compose.yaml` of 3.3.2 on the
  release image `apache/airflow:3.3.2`: `airflow-apiserver` (web UI and API
  on :8080), `airflow-scheduler`, `airflow-dag-processor`,
  `airflow-triggerer`, the one-shot `airflow-init` (migrations and the admin
  user) and `postgres:16`.
- `LocalExecutor` instead of `CeleryExecutor`: no Redis and no Celery worker,
  which would not fit the account next to the other four processes. The API
  server runs one worker (`AIRFLOW__API__WORKERS=1`) and at most four tasks
  run at once (`AIRFLOW__CORE__PARALLELISM=4`): LocalExecutor forks a
  ~150 MB process per task, and with the default 32 the scheduler was
  OOM-killed on the first example DAG run.
- Give the account 3072 MB: measured ~1.25 GB idle across the five
  containers, more while tasks run.
- DAGs, logs, config and plugins are named volumes; upstream bind-mounts them
  from the compose directory, which is the wiped checkout here.
- `hooks/prepare.sh` writes the database password (and the connection URL
  that carries it), `AIRFLOW__CORE__FERNET_KEY`,
  `AIRFLOW__API_AUTH__JWT_SECRET` and `AIRFLOW__API__SECRET_KEY` once to
  `~/.panelalpha/airflow/airflow.env`.
- `AIRFLOW__API__BASE_URL` is the account's public URL.
- A no-op `ready` service waits for `/api/v2/monitor/health`, so the deploy
  ends when the UI answers.

## First run

Upstream's: `airflow-init` creates the admin `airflow` / `airflow`. Example
DAGs are loaded, paused (upstream's defaults).

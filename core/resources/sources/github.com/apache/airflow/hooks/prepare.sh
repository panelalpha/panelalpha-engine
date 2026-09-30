#!/bin/bash
# Secrets generated once into ~/.panelalpha: ~/project is wiped on every deploy,
# the Postgres volume keeps its first password and the Fernet key encrypts
# stored connections and variables.
set -e
STORE="${HOME}/.panelalpha/airflow"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/airflow.env" ]; then
    db="$(openssl rand -hex 24)"
    fernet="$(openssl rand -base64 32 | tr '+/' '-_' | tr -d '\n')"
    (umask 077; {
        printf 'POSTGRES_PASSWORD=%s\n' "$db"
        printf 'AIRFLOW__DATABASE__SQL_ALCHEMY_CONN=postgresql+psycopg2://airflow:%s@postgres/airflow\n' "$db"
        printf 'AIRFLOW__CORE__FERNET_KEY=%s\n' "$fernet"
        printf 'AIRFLOW__API_AUTH__JWT_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'AIRFLOW__API__SECRET_KEY=%s\n' "$(openssl rand -hex 32)"
    } > "${STORE}/airflow.env")
fi
chmod 600 "${STORE}/airflow.env"

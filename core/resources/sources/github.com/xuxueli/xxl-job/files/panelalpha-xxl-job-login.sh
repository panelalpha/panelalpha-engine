# Sets the engine's login on XXL-JOB's built-in admin (admin / 123456, seeded by
# doc/db/tables_xxl_job.sql). Sourced by the MySQL image's entrypoint after that
# file, so it runs once, on a new database: a password changed in XXL-JOB is never
# touched. Sourced, so no `exit` here.
if [ -n "${XXL_JOB_ADMIN_PASSWORD:-}" ]; then
    pa_sql_quote() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/''/g"; }
    # XXL-JOB keeps an unsalted SHA-256 (Sha256Tool.sha256), as MySQL's SHA2(..., 256) gives it.
    docker_process_sql --database=xxl_job <<SQL
UPDATE xxl_job_user
   SET username = '$(pa_sql_quote "${XXL_JOB_ADMIN_USERNAME:-admin}")',
       password = SHA2('$(pa_sql_quote "${XXL_JOB_ADMIN_PASSWORD}")', 256)
 WHERE username = 'admin' AND password = SHA2('123456', 256);
SQL
    echo "[panelalpha] xxl-job: the admin's login is the one the engine generated"
    unset -f pa_sql_quote
fi

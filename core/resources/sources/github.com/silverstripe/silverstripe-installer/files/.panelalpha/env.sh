# Sourced by this recipe's deploy stages and by the entrypoint's own shell.
#
# The engine provisions the account's database (manifest `database: mysql`) and
# hands it over as DB_*; SilverStripe reads SS_DATABASE_*. Mapping it here and
# sourcing it keeps the password in the process environment and out of
# ~/project, which is both web-adjacent and wiped on every redeploy.
#
# A dot-directory: Apache's own config denies any path with a /. segment, and
# this one is above the document root (public/) as well.
SS_DATABASE_CLASS="${SS_DATABASE_CLASS:-MySQLDatabase}"
SS_DATABASE_SERVER="$DB_HOST"
SS_DATABASE_PORT="$DB_PORT"
SS_DATABASE_NAME="$DB_DATABASE"
SS_DATABASE_USERNAME="$DB_USERNAME"
SS_DATABASE_PASSWORD="$DB_PASSWORD"
# So Director builds absolute URLs on the name the visitor typed, and on https,
# rather than on whatever Host header reached the container behind the proxy.
SS_BASE_URL="${APP_URL:-}"
export SS_DATABASE_CLASS SS_DATABASE_SERVER SS_DATABASE_PORT \
       SS_DATABASE_NAME SS_DATABASE_USERNAME SS_DATABASE_PASSWORD SS_BASE_URL

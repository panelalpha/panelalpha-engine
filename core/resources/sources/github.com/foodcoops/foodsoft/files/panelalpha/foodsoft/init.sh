#!/bin/sh
# One-shot, run to completion before web/worker/cron are allowed to serve.
# Runs as root (compose `user: 0:0`) only to fix ownership of the named storage
# volume, then loads the schema and seeds the admin. web/worker/cron wait on
# this with service_completed_successfully, so a failed migration fails the
# deploy instead of publishing a broken site, and the admin exists before the
# first request.
set -e

say() { echo "[panelalpha/init] $*"; }
cd /usr/src/app

# A named volume mounts root-owned and shadows the image's build-time
# `chown nobody storage`, so ActiveStorage (running as nobody in web/worker)
# could not write uploads. Restore the owner here, where we are root.
say "fixing storage ownership"
chown -R nobody:nogroup /usr/src/app/storage 2>/dev/null || true

# compose already gates this on the db healthcheck; this is a second belt for
# the window where mariadb answers on its bootstrap socket while still
# restarting for the real one. Ask through ActiveRecord so the driver and DSN
# are exactly what migrate will use.
i=0
until bundle exec rails runner "ActiveRecord::Base.connection.execute('SELECT 1')" >/dev/null 2>&1
do
    i=$((i + 1))
    if [ "${i}" -ge 60 ]; then
        say "database never accepted a connection"
        exit 1
    fi
    sleep 2
done

# Has the schema been loaded already? On the very first deploy the app database
# is empty; on every redeploy it is not, and its data must be left untouched.
if bundle exec rails runner "exit(ActiveRecord::Base.connection.table_exists?(:users) ? 0 : 1)" >/dev/null 2>&1; then
    say "schema present, applying any new migrations"
    bundle exec rails db:migrate
else
    say "empty database, loading schema"
    # schema:load, not migrate: the ancient numbered migrations (001/002/008)
    # create an 'admin' user with a fixed password as a side effect; schema.rb
    # is the clean final state with no seeded users.
    DISABLE_DATABASE_ENVIRONMENT_CHECK=1 bundle exec rails db:schema:load
    bundle exec rails db:migrate
fi

# Seed exactly one admin (generated password) and the minimum lookup rows the
# UI needs -- only when there is no user yet. The stock db/seeds.rb (small.en)
# is deliberately never run: it creates demo users whose password is "secret".
#
# The count is read through a unique marker, not by comparing the whole output
# to "0": RAILS_LOG_TO_STDOUT puts the "Loading app configuration" line on
# stdout ahead of the number, so a bare `print User.count` never equals "0".
user_count="$(bundle exec rails runner 'print "PA_USER_COUNT=#{User.count}"' 2>/dev/null \
    | grep -oE 'PA_USER_COUNT=[0-9]+' | head -n1 | cut -d= -f2)"
if [ "${user_count:-}" = "0" ]; then
    say "empty user table, seeding the admin"
    bundle exec rails runner /pa/seed.rb
else
    say "users already present (count=${user_count:-unknown}), leaving them untouched"
fi

say "done"

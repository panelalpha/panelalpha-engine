#!/bin/bash
# First deploy: upstream's keygen:setup (schema load + account + admin).
# Later deploys: only db:migrate, since setup's db:schema:load would wipe the data.
set -e
until pg_isready -q -d "$DATABASE_URL"; do sleep 1; done
if [ "$(psql "$DATABASE_URL" -tAc "SELECT to_regclass('public.accounts') IS NOT NULL AND EXISTS (SELECT 1 FROM accounts WHERE id = '${KEYGEN_ACCOUNT_ID}')" 2>/dev/null || true)" = "t" ]; then
    exec bundle exec rails db:migrate
fi
exec bundle exec rails keygen:setup

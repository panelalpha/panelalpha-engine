#!/bin/sh
# One-shot before the web service publishes its port: create/migrate the SQLite
# databases (seeding admin@admin.com / foobar on a new one), then replace that
# seeded login with the engine's one (~/.panelalpha/app-credentials.env).
set -e
cd /rails
./bin/rails db:prepare
./bin/rails runner /panelalpha/rotate-admin.rb

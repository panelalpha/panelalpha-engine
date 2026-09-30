#!/bin/sh
# The image's own CMD (migrate, seeds, start) with the default admin password
# replaced before the server opens its port.
set -e
/app/bin/claper eval Claper.Release.migrate
/app/bin/claper eval Claper.Release.seeds
/app/bin/claper eval 'Code.eval_file("/panelalpha/rotate-admin.exs")'
exec /app/bin/claper start

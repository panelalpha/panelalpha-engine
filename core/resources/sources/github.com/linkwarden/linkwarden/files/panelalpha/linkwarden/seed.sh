#!/bin/sh
# Same migration step as the image's own CMD, then the admin seed.
set -e
export PATH=/data/node_modules/.bin:$PATH
prisma migrate deploy --schema=/data/packages/prisma/schema.prisma
exec node /pa/seed.js

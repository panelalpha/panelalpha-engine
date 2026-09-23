#!/bin/sh
# Runs in the `init` service, which is an alpine that exits before the app
# starts. Two jobs, both of them things a `FROM scratch` image cannot do for
# itself.
set -e

# 1. Ownership. Docker initialises a named volume from the image's directory
# when the mount point exists there -- ownership included -- and creates it
# root-owned 0755 when it does not. Neither /db (the image's
# VIKUNJA_DATABASE_PATH) nor the files basepath is in a scratch image, so both
# arrive root-owned and the Dockerfile's `USER 1000` cannot write to either:
# `could not open database file [uid=1000, gid=0]`, fatal, on every boot.
chown 1000:1000 /db /files
chmod 700 /db

# 2. service.publicurl, which has to be the account's public address and is
# the one value neither the checkout nor the recipe knows.
#
# hooks/prepare.sh cannot supply it: it runs before detection, so the generated
# compose file does not exist yet, and the account's own ~/<domain>/ directory
# is created a few seconds later still. The app container cannot copy it out of
# its own environment the way the pretix recipe does -- the engine does write it
# there, as SITE_URL among other aliases (ComposeHarden::urlEnvironment()), but
# a scratch image has no shell to copy it with. So it is read here, out of the
# generated compose file mounted at /compose.yml, and written where Vikunja
# looks for a config file: viper.AddConfigPath("/etc/vikunja/"), mounted on this
# side as /config. Environment variables still outrank it, so an account that
# sets VIKUNJA_SERVICE_PUBLICURL in its env vars keeps the last word.
url=$(awk -F 'SITE_URL:' '/SITE_URL:/ { print $2; exit }' /compose.yml | tr -d " '\"\r")

mkdir -p /config
case "$url" in
    http://*|https://*)
        printf 'service:\n  publicurl: "%s"\n' "$url" > /config/config.yml
        ;;
    *)
        # No usable URL, which would otherwise be a boot-time
        # `log.Fatalf("service.publicurl is required when cors.enable is true")`
        # -- cors.enable defaults to true and publicurl to empty, so a Vikunja
        # that knows nothing about itself cannot start at all. CORS only matters
        # for a frontend served from another origin; this one comes from the
        # same binary on the same host, and routes/static.go falls back to
        # window.API_URL = '/api/v1' when publicurl is empty.
        printf 'cors:\n  enable: false\n' > /config/config.yml
        ;;
esac
chmod 644 /config/config.yml

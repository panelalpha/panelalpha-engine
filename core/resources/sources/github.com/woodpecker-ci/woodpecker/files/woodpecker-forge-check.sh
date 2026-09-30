#!/bin/sh
# Woodpecker's server exits with "forge not configured" unless one forge is
# selected, and nobody can log in without that forge's OAuth application.
missing=""
forge=""
for f in GITHUB GITLAB GITEA FORGEJO BITBUCKET BITBUCKET_DC; do
    [ "$(printenv "WOODPECKER_$f")" = "true" ] && forge="$f"
done
[ -n "${WOODPECKER_ADDON_FORGE:-}" ] && forge="addon"
[ -n "$forge" ] || missing="$missing
  one forge switch: WOODPECKER_GITHUB, WOODPECKER_GITLAB, WOODPECKER_GITEA, WOODPECKER_FORGEJO, WOODPECKER_BITBUCKET or WOODPECKER_BITBUCKET_DC set to true (plus WOODPECKER_FORGE_URL for a self-hosted forge)"
env | grep -Eq '^WOODPECKER_(FORGE|GITHUB|GITLAB|GITEA|FORGEJO|BITBUCKET|BITBUCKET_DC)_CLIENT(_ID)?(_FILE)?=.' \
    || [ "$forge" = "addon" ] || missing="$missing
  WOODPECKER_FORGE_CLIENT (the OAuth application's client ID)"
env | grep -Eq '^WOODPECKER_(FORGE|GITHUB|GITLAB|GITEA|FORGEJO|BITBUCKET|BITBUCKET_DC)_(CLIENT_)?SECRET(_FILE)?=.' \
    || [ "$forge" = "addon" ] || missing="$missing
  WOODPECKER_FORGE_SECRET (the OAuth application's client secret)"

if [ -n "$missing" ]; then
    echo "woodpecker: set in the project's environment variables, then redeploy:$missing
Create the OAuth application on the forge with the callback URL <site address>/authorize." >&2
    exit 1
fi
echo "woodpecker: forge $forge configured"

#!/bin/sh
# Fails the deploy, naming each variable, while OpenBot's required settings are unset.
missing=""
[ -n "${INTELLIGENCE_API_KEY}" ] || missing="${missing} INTELLIGENCE_API_KEY"
if [ -z "${GOOGLE_OAUTH_CLIENT_ID}${MICROSOFT_OAUTH_CLIENT_ID}${OKTA_OAUTH_CLIENT_ID}" ]; then
    missing="${missing} GOOGLE_OAUTH_CLIENT_ID+GOOGLE_OAUTH_CLIENT_SECRET(or MICROSOFT_OAUTH_*/OKTA_OAUTH_*)"
fi
[ -n "${INITIAL_ADMIN_EMAILS}" ] || missing="${missing} INITIAL_ADMIN_EMAILS"
if [ -n "${missing}" ]; then
    echo "OpenBot needs these project environment variables set:${missing}" >&2
    echo "INTELLIGENCE_API_KEY is a CopilotKit Intelligence key; sign-in needs an OAuth app whose redirect URI is <site>/api/auth/callback/<google|microsoft|okta>." >&2
    exit 1
fi

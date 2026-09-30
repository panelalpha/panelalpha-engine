#!/bin/sh
# Fider panics at boot without a mail transport; mirror its own check
# (app/pkg/env/env.go) so the deploy fails naming each missing variable.
missing=""
need() { eval "v=\${$1:-}"; [ -n "$v" ] || missing="$missing $1"; }

type="${EMAIL:-}"
if [ -z "$type" ]; then
    if [ -n "${EMAIL_MAILGUN_API:-}" ]; then type=mailgun
    elif [ -n "${EMAIL_AWSSES_ACCESS_KEY_ID:-}" ]; then type=awsses
    else type=smtp; fi
fi

need EMAIL_NOREPLY
case "$type" in
    mailgun) need EMAIL_MAILGUN_API; need EMAIL_MAILGUN_DOMAIN ;;
    awsses) need EMAIL_AWSSES_REGION; need EMAIL_AWSSES_ACCESS_KEY_ID; need EMAIL_AWSSES_SECRET_ACCESS_KEY ;;
    *) need EMAIL_SMTP_HOST; need EMAIL_SMTP_PORT ;;
esac

if [ -n "$missing" ]; then
    echo "fider: set in the project's environment variables:$missing (Fider sends sign-in links by e-mail and will not start without a mail transport; EMAIL_SMTP_USERNAME / EMAIL_SMTP_PASSWORD too if your server needs a login), then redeploy." >&2
    exit 1
fi
echo "fider: e-mail settings present ($type)"

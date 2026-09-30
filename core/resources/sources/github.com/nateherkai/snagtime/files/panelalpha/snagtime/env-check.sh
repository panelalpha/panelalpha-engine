#!/bin/sh
# Snagtime's production runtime refuses to start without Google OAuth, Stripe
# test keys and TLS SMTP (apps/web/src/server/auth/session.ts). Name what is missing.
bad=""
for v in GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET STRIPE_SECRET_KEY STRIPE_WEBHOOK_SECRET \
         SMTP_HOST SMTP_PORT SMTP_TLS_MODE SMTP_USER SMTP_PASSWORD \
         EMAIL_FROM EMAIL_REPLY_TO EMAIL_SENDER_DOMAIN; do
    eval "val=\${$v:-}"
    [ -n "$val" ] || bad="$bad $v"
done
case "${GOOGLE_CLIENT_ID:-}" in ""|*.apps.googleusercontent.com) ;; *) bad="$bad GOOGLE_CLIENT_ID(must end in .apps.googleusercontent.com)" ;; esac
case "${STRIPE_SECRET_KEY:-}" in ""|sk_test_*) ;; *) bad="$bad STRIPE_SECRET_KEY(must be a Stripe test key, sk_test_...)" ;; esac
case "${STRIPE_WEBHOOK_SECRET:-}" in ""|whsec_*) ;; *) bad="$bad STRIPE_WEBHOOK_SECRET(must start with whsec_)" ;; esac
case "${SMTP_TLS_MODE:-}" in ""|implicit|starttls) ;; *) bad="$bad SMTP_TLS_MODE(implicit or starttls)" ;; esac
if [ -n "$bad" ]; then
    echo "snagtime: missing or invalid project environment variable(s):$bad. EMAIL_FROM's address must be @EMAIL_SENDER_DOMAIN. Set them and redeploy." >&2
    exit 1
fi
echo "snagtime: provider configuration present"

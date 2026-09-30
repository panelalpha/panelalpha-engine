#!/bin/sh
# .env is copied from .env.example, so its placeholders count as unset.
missing=""
case "${TELEGRAM_API_ID:-}" in ""|your_api_id_here) missing="$missing TELEGRAM_API_ID";; esac
case "${TELEGRAM_API_HASH:-}" in ""|your_api_hash_here) missing="$missing TELEGRAM_API_HASH";; esac
case "${TELEGRAM_PHONE:-}" in ""|+1234567890) missing="$missing TELEGRAM_PHONE";; esac
if [ -n "$missing" ]; then
    echo "telegram-archive: missing project environment variable(s):$missing. Create an app at https://my.telegram.org/apps, set TELEGRAM_API_ID, TELEGRAM_API_HASH and TELEGRAM_PHONE (international format), then redeploy." >&2
    exit 1
fi
echo "telegram-archive: Telegram API login set"

#!/bin/sh
# TimeTagger has no login without TIMETAGGER_CREDENTIALS ("user:bcrypt-hash,...").
help='Generate "user:hash" at https://timetagger.app/cred and set it as the project environment variable TIMETAGGER_CREDENTIALS, writing every $ as $$ (compose would otherwise expand it), then redeploy.'

if [ -z "${TIMETAGGER_CREDENTIALS:-}" ]; then
    echo "timetagger: TIMETAGGER_CREDENTIALS is not set. $help" >&2
    exit 1
fi

for entry in $(echo "$TIMETAGGER_CREDENTIALS" | tr ',;' '  '); do
    hash="${entry#*:}"
    case "$hash" in
        '$2'*) [ "${#hash}" -eq 60 ] && continue ;;
    esac
    echo "timetagger: TIMETAGGER_CREDENTIALS entry for '${entry%%:*}' is not a user:bcrypt-hash pair (60-character hash expected). $help" >&2
    exit 1
done
echo "timetagger: credentials set"

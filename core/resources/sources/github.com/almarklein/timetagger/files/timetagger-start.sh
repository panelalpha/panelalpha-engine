#!/bin/sh
# Without the customer's own TIMETAGGER_CREDENTIALS, hash the engine's login with the image's bcrypt.
set -e
if [ -z "${TIMETAGGER_CREDENTIALS:-}" ]; then
    hash=$(python -c 'import bcrypt, os; print(bcrypt.hashpw(os.environ["TIMETAGGER_PASSWORD"].encode(), bcrypt.gensalt()).decode())')
    export TIMETAGGER_CREDENTIALS="${TIMETAGGER_USERNAME}:${hash}"
fi
exec python -m timetagger

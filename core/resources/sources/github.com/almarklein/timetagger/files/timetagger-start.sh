#!/bin/sh
# The engine's login, hashed with the image's bcrypt, first in TIMETAGGER_CREDENTIALS: the customer's own
# user:hash pairs come after it, so they still sign in, and one with the same username wins over it.
set -e
hash=$(python -c 'import bcrypt, os; print(bcrypt.hashpw(os.environ["TIMETAGGER_PASSWORD"].encode(), bcrypt.gensalt()).decode())')
export TIMETAGGER_CREDENTIALS="${TIMETAGGER_USERNAME}:${hash}${TIMETAGGER_CREDENTIALS:+,${TIMETAGGER_CREDENTIALS}}"
exec python -m timetagger

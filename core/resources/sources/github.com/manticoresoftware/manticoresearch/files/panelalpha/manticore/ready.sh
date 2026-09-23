#!/bin/sh
# The deploy's verdict on authentication, and the reason this recipe is
# shippable rather than a way to publish an open search engine.
#
# Two assertions, both against the running daemon, neither inferred:
#
#   1. an anonymous request is refused                 -> nothing is open;
#   2. the generated credential is accepted            -> the account has the
#                                                         login it was told it
#                                                         has.
#
# Either one failing exits non-zero. On its own that would change nothing --
# `docker compose up -d` starts containers without waiting for them, and a
# one-shot service exiting 1 leaves it exiting 0 and the deploy green, which is
# measured, not theoretical. The `verified` service is what closes that: its
# `service_completed_successfully` dependency on this one makes compose wait
# here and fail with "dependency failed to start".
#
# That is the intended behaviour: an instance that could not lock itself must
# not reach a customer's domain, and an instance that locked itself out of its
# owner's hands is not a deploy either.
#
# This is the second of two refusals, not the only one. bootstrap.sh makes the
# same anonymous check inside the daemon's container and kills searchd when it
# fails, so an open instance stops being served whether or not anyone reads a
# deploy log.
#
# busybox wget, because this is alpine and adding curl would mean an apk
# mirror in the deploy path. -S writes the status line to stderr; wget's own
# exit code is useless here because 401 -- the answer we want -- is a failure
# to it.
set -u

ENDPOINT=http://manticore:9308/

status() {
    wget -q -S -O /dev/null "$@" "${ENDPOINT}" 2>&1 \
        | sed -n 's|^[[:space:]]*HTTP/1\.[01][[:space:]]\([0-9][0-9][0-9]\).*|\1|p' \
        | head -1
}

USER_NAME=${MANTICORE_ADMIN_USER:-}
USER_PASS=${MANTICORE_ADMIN_PASSWORD:-}
if [ -z "${USER_NAME}" ] || [ -z "${USER_PASS}" ]; then
    echo "[manticore] FAILED: no credentials were generated for this account" >&2
    exit 1
fi
BASIC="Authorization: Basic $(printf '%s:%s' "${USER_NAME}" "${USER_PASS}" | base64 | tr -d '\n')"

# The bootstrap runs in the other container while this one starts, so the
# credential is allowed to be a few seconds late. Nothing else is: the daemon
# is already healthy by the time this runs.
authed=
i=0
while [ "${i}" -lt 120 ]; do
    authed=$(status --header="${BASIC}")
    [ "${authed}" = "200" ] && break
    i=$((i + 1))
    sleep 1
done

anon=$(status)

echo "[manticore] anonymous request -> ${anon:-no answer}; authenticated request -> ${authed:-no answer}"

if [ "${anon}" != "401" ]; then
    cat >&2 <<EOF
[manticore] FAILED: an anonymous request was answered with '${anon:-no answer}', not 401.
[manticore] This instance is not requiring authentication. Refusing to finish the
[manticore] deploy rather than publish a writable search engine on a public domain.
[manticore] Check that searchd_auth=1 is in ~/.panelalpha/manticore/manticore.env and
[manticore] that MANTICORE_IMAGE is a release that supports authentication (>= 13.x).
EOF
    exit 1
fi

if [ "${authed}" != "200" ]; then
    cat >&2 <<EOF
[manticore] FAILED: the account's own credentials were answered with
[manticore] '${authed:-no answer}', not 200. The daemon is refusing everyone, including
[manticore] its owner. If the password in ~/.panelalpha/manticore/manticore.env was
[manticore] edited by hand, it no longer matches the hash in auth.json inside the
[manticore] manticore_data volume -- put the real one back, or change it through
[manticore] the daemon. See ~/.panelalpha/manticore/credentials.txt.
EOF
    exit 1
fi

echo "[manticore] authentication verified: anonymous requests refused, this account's login works"
exit 0

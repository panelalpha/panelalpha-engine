#!/bin/sh
# PanelAlpha wrapper for the official SCM-Manager image.
#
# On the first boot of an empty data volume, SCM-Manager prints a one-time
# startup token to its log and waits for someone to create the first admin with
# it (POST /api/v2/initialization/adminAccount). This wrapper reads that token
# from the boot log and creates the admin non-interactively, with the password
# the prepare hook generated once into ~/.panelalpha/scm-manager/ and mounted
# here read-only, then completes the plugin wizard installing nothing so the
# site opens in normal state. The SCM-Manager server itself is upstream's and is
# started unmodified. The seeding is idempotent: on a redeploy where the admin
# already exists the server reports it is initialized and nothing is seeded.
set -u

API="http://127.0.0.1:8080/api/v2"
BOOT_LOG="/tmp/scm-boot.log"
PIPE="/tmp/scm-boot.pipe"
PW_FILE="/run/scm/admin_password"
ADMIN_USER="${SCM_ADMIN_USER:-pa-admin}"

# A syntactically-valid contact email for the admin. SCM-Manager validates the
# format, so a bare hostname (admin@scmman) is rejected. Prefer the account's
# injected public FQDN (SERVER_NAME / PUBLIC_URL), require a dotted domain, and
# fall back to a valid literal. No mail is ever sent to it.
_host() {
  h="${SERVER_NAME:-}"
  if [ -z "$h" ] && [ -n "${PUBLIC_URL:-}" ]; then
    h="${PUBLIC_URL#*://}"; h="${h%%/*}"; h="${h%%:*}"
  fi
  case "$h" in *.*) printf '%s' "$h" ;; *) printf '' ;; esac
}
_email() {
  if [ -n "${SCM_ADMIN_EMAIL:-}" ] && printf '%s' "$SCM_ADMIN_EMAIL" | grep -qE '^[^@ ]+@[^@ ]+\.[^@ ]+$'; then
    printf '%s' "$SCM_ADMIN_EMAIL"; return
  fi
  H=$(_host)
  if [ -n "$H" ]; then printf 'admin@%s' "$H"; else printf 'admin@example.com'; fi
}
ADMIN_EMAIL="$(_email)"

seed() {
  # Wait for the API to answer (the "version" field is always present), then
  # read the initialization state. On an already-set-up instance (a redeploy),
  # the "initialization" field is absent, which is a normal no-op - not a
  # timeout.
  BODY=""; UP=""
  i=0
  while [ "$i" -lt 180 ]; do
    BODY=$(wget -qO- "$API/" 2>/dev/null)
    if printf '%s' "$BODY" | grep -q '"version"'; then UP=1; break; fi
    i=$((i + 1)); sleep 1
  done
  if [ -z "$UP" ]; then
    echo "[panelalpha] scm: API never answered; skipping admin seeding" >&2; return
  fi
  STATE=$(printf '%s' "$BODY" | sed -n 's/.*"initialization":"\([^"]*\)".*/\1/p')
  if [ "$STATE" != "adminAccount" ]; then
    echo "[panelalpha] scm: already initialized (state=${STATE:-none}); no seeding needed" >&2; return
  fi
  if [ ! -s "$PW_FILE" ]; then
    echo "[panelalpha] scm: admin password file $PW_FILE missing; cannot seed admin" >&2; return
  fi
  PW=$(cat "$PW_FILE")

  # The startup token is printed once, framed in a banner: "==   <token>   ==".
  TOKEN=""
  j=0
  while [ "$j" -lt 60 ]; do
    TOKEN=$(grep -oE '== +[A-Za-z0-9]{16,} +==' "$BOOT_LOG" 2>/dev/null | grep -oE '[A-Za-z0-9]{16,}' | head -1)
    [ -n "$TOKEN" ] && break
    j=$((j + 1)); sleep 1
  done
  if [ -z "$TOKEN" ]; then
    echo "[panelalpha] scm: startup token not found in log; cannot seed admin" >&2; return
  fi

  # Create the admin. The password stays out of argv (written to a temp file).
  printf '{"startupToken":"%s","userName":"%s","displayName":"%s","email":"%s","password":"%s","passwordConfirmation":"%s"}' \
    "$TOKEN" "$ADMIN_USER" "PanelAlpha Admin" "$ADMIN_EMAIL" "$PW" "$PW" > /tmp/scm-admin.json
  code=$(wget -qO- --server-response --header="Content-Type: application/json" \
    --post-file=/tmp/scm-admin.json "$API/initialization/adminAccount" 2>&1 \
    | sed -n 's|.*HTTP/[0-9.]* \([0-9]*\).*|\1|p' | head -1)
  rm -f /tmp/scm-admin.json
  echo "[panelalpha] scm: admin '$ADMIN_USER' creation -> HTTP ${code:-?}" >&2

  # Complete the plugin wizard with an empty selection so the app leaves the
  # initialization flow without reaching out to the plugin center.
  TOK=$(wget -qO- --header="Content-Type: application/json" \
    --post-data="{\"grant_type\":\"password\",\"username\":\"$ADMIN_USER\",\"password\":\"$PW\"}" \
    "$API/auth/access_token" 2>/dev/null)
  if [ -n "$TOK" ]; then
    wget -qO- --header="Authorization: Bearer $TOK" --header="Content-Type: application/json" \
      --post-data='{"pluginSetIds":[]}' "$API/initialization/pluginWizard" >/dev/null 2>&1 \
      && echo "[panelalpha] scm: plugin wizard completed (no plugins installed)" >&2
  fi
}

# Tee the server's output so the container log still shows boot progress while
# the seeder reads the one-time token from BOOT_LOG.
rm -f "$PIPE"; mkfifo "$PIPE"
tee "$BOOT_LOG" < "$PIPE" &

seed &

# Run the upstream server as a direct child so signals reach it cleanly.
/opt/scm-server/bin/scm-server > "$PIPE" 2>&1 &
SRV=$!
trap 'kill -TERM "$SRV" 2>/dev/null' TERM INT
wait "$SRV"

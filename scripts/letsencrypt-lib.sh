#!/bin/bash
#
# Shared pieces of the engine's Let's Encrypt scripts. Sourced, never run.
#
# The engine keeps two certificate lineages under /etc/letsencrypt:
#
#   panelalpha-engine-cert     a DNS name, classic 90-day profile. Either the
#                              administrator's domain (setting `cert_domain`)
#                              or the default derived from the public address,
#                              203-0-113-7.panelalpha.direct.
#   panelalpha-engine-ip-cert  the bare public IP, which Let's Encrypt issues
#                              only under the short-lived profile (~6 days).
#                              What a fresh install serves, having been given
#                              no name to ask for.
#
# Either can be the served one -- whichever was installed last wins, and
# le_served_lineage reads crt/server.cert to say which it is. Whatever is
# served lives in crt/server.cert + crt/server.key, which is what
# core-http's nginx loads on :2011. Renewal never edits that pair by itself:
# `certbot renew` runs inside the certbot container, where neither this tree
# nor docker exist, so a certbot deploy hook cannot copy anything. Instead
# letsencrypt-renew.sh calls install_lineage after renewing, and that copies
# only when the file changed.

LE_ENGINE_DIR="${LE_ENGINE_DIR:-$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)}"
LE_COMPOSE_FILE="${LE_ENGINE_DIR}/docker-compose.yml"
LE_CRT_DIR="${LE_ENGINE_DIR}/crt"
# Overridable so the scripts can be exercised against a scratch tree.
LE_LETSENCRYPT_DIR="${LE_LETSENCRYPT_DIR:-/etc/letsencrypt}"
LE_LOG_DIR="${LE_LOG_DIR:-/opt/panelalpha/log/letsencrypt}"
LE_CRON_FILE="${LE_CRON_FILE:-/etc/cron.d/panelalpha-letsencrypt}"

LE_CERT_NAME="${LE_CERT_NAME:-panelalpha-engine-cert}"
LE_IP_CERT_NAME="${LE_IP_CERT_NAME:-panelalpha-engine-ip-cert}"
LE_BASE_DOMAIN="${PANELALPHA_CERT_BASE_DOMAIN:-panelalpha.direct}"
LE_CERTBOT_IMAGE="${LE_CERTBOT_IMAGE:-certbot/certbot}"

le_info() { echo -e ">>> \e[32m$1\e[39m"; }
le_warn() { echo -e ">>> \e[33m$1\e[39m" >&2; }
le_error() {
    echo -e ">>> \e[31m$1\e[39m" >&2
    exit 1
}

# The address the outside world reaches this host on. The default route's
# source address, unless that is private, in which case whoever answers on
# the internet knows better (NAT, cloud hosts with a private primary address).
le_public_ip() {
    local ip
    ip=$(ip route get 8.8.8.8 2>/dev/null | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
    if [ -z "$ip" ] || le_ip_is_private "$ip"; then
        ip=$(curl -s4 --max-time 10 icanhazip.com 2>/dev/null | tr -d '[:space:]')
    fi
    echo "$ip"
}

le_ip_is_private() {
    # ipcalc is what installer.sh relies on; fall back to the RFC 1918 and
    # link-local ranges when it is missing, so a plain workstation can run
    # the parser tests.
    if command -v ipcalc >/dev/null 2>&1; then
        ipcalc "$1" 2>/dev/null | grep -q 'Private Internet'
        return
    fi
    case "$1" in
    10.* | 192.168.* | 169.254.* | 127.*) return 0 ;;
    172.1[6-9].* | 172.2[0-9].* | 172.3[01].*) return 0 ;;
    esac
    return 1
}

le_is_ipv4() {
    [[ "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
    local octet
    for octet in ${1//./ }; do
        [ "$octet" -le 255 ] || return 1
    done
}

# 203.0.113.7 -> 203-0-113-7.panelalpha.direct. The zone is a wildcard
# resolver (nip.io/sslip.io style): the dashed label resolves back to the
# address, so the name is valid the moment the host has a public IP.
le_default_domain() {
    local ip="$1" base="${2:-$LE_BASE_DOMAIN}"
    le_is_ipv4 "$ip" || return 1
    echo "${ip//./-}.${base}"
}

# A hostname certbot will accept: labels of letters, digits and hyphens, at
# least one dot, no trailing dot, not an address.
le_is_fqdn() {
    local name="$1"
    [ "${#name}" -le 253 ] || return 1
    le_is_ipv4 "$name" && return 1
    [[ "$name" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]]
}

# The addresses a name resolves to right now, one per line.
le_resolve() {
    getent ahostsv4 "$1" 2>/dev/null | awk '{print $1}' | sort -u
}

le_core_running() {
    docker compose -f "$LE_COMPOSE_FILE" ps --services --filter status=running 2>/dev/null | grep -qx core
}

# Settings live in the core database, and only the core container can reach
# it. Empty when core is down or the setting is unset -- callers fall back.
le_setting_get() {
    le_core_running || return 0
    docker compose -f "$LE_COMPOSE_FILE" exec -T core php artisan settings:get "$1" 2>/dev/null | tr -d '\r' | tail -n 1
}

le_setting_set() {
    le_core_running || return 1
    docker compose -f "$LE_COMPOSE_FILE" exec -T core php artisan settings:set "$1" "$2" >/dev/null 2>&1
}

# The certbot container binds :80 itself for the HTTP-01 challenge; sites-http
# is host-networked and holds that port while it runs. Bringing the profile
# service back is `up -d`, which re-enables its profile (see architecture.md).
le_release_port_80() {
    docker compose -f "$LE_COMPOSE_FILE" down sites-http >/dev/null 2>&1 || true
}

le_restore_stack() {
    docker compose -f "$LE_COMPOSE_FILE" up -d >/dev/null 2>&1 || true
}

# certbot [args...] inside the official image, with the host's account and
# lineage directories. Stdout/stderr pass through.
le_certbot() {
    docker run --rm --name certbot-engine \
        -v /etc/letsencrypt:/etc/letsencrypt \
        -v /var/lib/letsencrypt:/var/lib/letsencrypt \
        -p 80:80 \
        "$LE_CERTBOT_IMAGE" "$@"
}

# certbot refuses to create an account non-interactively without being told
# what to do about the email. An address gets expiry mail; none is allowed
# but has to be said out loud.
le_account_args() {
    local email="$1"
    if [ -n "$email" ]; then
        echo "--email" "$email" "--no-eff-email"
    else
        echo "--register-unsafely-without-email"
    fi
}

le_lineage_dir() {
    echo "${LE_LETSENCRYPT_DIR}/live/$1"
}

le_lineage_exists() {
    [ -f "$(le_lineage_dir "$1")/fullchain.pem" ] && [ -f "$(le_lineage_dir "$1")/privkey.pem" ]
}

# The names a certificate is for, one per line, sorted: its subjectAltName
# entries, or the subject CN when it carries none. Reads the leaf, so a
# fullchain is fine. Non-zero when the file is missing or names nothing.
le_cert_names() {
    local file="$1" names
    [ -f "$file" ] || return 1
    names=$(openssl x509 -in "$file" -noout -ext subjectAltName 2>/dev/null |
        tr ',' '\n' |
        sed -nE 's/^[[:space:]]*(DNS|IP Address):[[:space:]]*([^[:space:]]+)[[:space:]]*$/\2/p')
    if [ -z "$names" ]; then
        names=$(openssl x509 -in "$file" -noout -subject 2>/dev/null |
            sed -nE 's/.*CN[[:space:]]*=[[:space:]]*//p' |
            sed -E 's/[,/].*$//; s/[[:space:]]+$//')
    fi
    [ -n "$names" ] || return 1
    sort -u <<<"$names"
}

# Which lineage crt/server.cert currently holds, by the names on it rather
# than its bytes: a lineage renewed a moment ago no longer matches byte for
# byte, but it is still the one being served. Prints nothing when the served
# certificate is neither of ours -- the installer's self-signed pair, or an
# operator's own.
le_served_lineage() {
    local names name
    names=$(le_cert_names "${LE_CRT_DIR}/server.cert") || return 0
    for name in "$LE_CERT_NAME" "$LE_IP_CERT_NAME"; do
        le_lineage_exists "$name" || continue
        if [ "$names" = "$(le_cert_names "$(le_lineage_dir "$name")/fullchain.pem")" ]; then
            echo "$name"
            return 0
        fi
    done
}

# Copy a lineage over the served pair. Nothing happens when the served files
# already hold that certificate, so cron can call this every run; when they
# do change, core-http's nginx is reloaded -- it reads the files only at start
# or reload, and the old request script did neither, which left the fresh
# certificate on disk and the self-signed one on :2011 until the next reboot.
#
#   install_lineage NAME [TARGET_BASENAME]
#
# TARGET_BASENAME defaults to "server" (crt/server.cert + crt/server.key).
# Returns 0 whether or not anything was copied; 1 when the lineage is missing.
le_install_lineage() {
    local name="$1" target="${2:-server}"
    local live cert key
    live=$(le_lineage_dir "$name")
    cert="${LE_CRT_DIR}/${target}.cert"
    key="${LE_CRT_DIR}/${target}.key"

    if ! le_lineage_exists "$name"; then
        le_warn "No certificate lineage named ${name} under ${LE_LETSENCRYPT_DIR}/live"
        return 1
    fi

    mkdir -p "$LE_CRT_DIR"
    if [ -f "$cert" ] && cmp -s "$live/fullchain.pem" "$cert" && cmp -s "$live/privkey.pem" "$key"; then
        return 0
    fi

    [ -f "$cert" ] && cp -f "$cert" "${cert}.bak"
    [ -f "$key" ] && cp -f "$key" "${key}.bak"
    # Follow the symlinks: live/ points into archive/, and a copied link would
    # dangle inside any container that mounts crt/ alone.
    cp -fL "$live/fullchain.pem" "$cert"
    cp -fL "$live/privkey.pem" "$key"
    chmod 644 "$cert"
    chmod 600 "$key"

    # The pair has to go together, and nginx will not say so if it does not.
    # Given a certificate and a key that do not match, `nginx -t` passes, nginx
    # starts, nothing is logged -- and then *every* TLS handshake is refused
    # with `SSL alert number 40`. On crt/server.cert that takes the whole
    # control plane down: no GET /system/info, no MCP, no deploys, and it reads
    # as a network fault.
    #
    # Observed on 2.29.1.58, where a rotation left crt/server.cert holding one
    # certificate and crt/server.key holding another's key. The API answered
    # nothing on :2011 until the .bak pair was put back.
    #
    # Checked *before* the reload, so a bad lineage leaves the previous pair
    # in place and the engine still serving, rather than replacing it with one
    # that cannot complete a handshake. Putting the backup back is what the
    # .bak files are for; this is the case that needs them.
    if ! le_cert_key_match "$cert" "$key"; then
        le_warn "Refusing ${name}: crt/${target}.cert and crt/${target}.key are not a pair"
        [ -f "${cert}.bak" ] && cp -f "${cert}.bak" "$cert"
        [ -f "${key}.bak" ] && cp -f "${key}.bak" "$key"
        le_warn "Left the previous crt/${target} pair in place; :2011 keeps serving it"
        return 1
    fi

    le_info "Installed ${name} as crt/${target}.cert"

    # Reload, not restart: :2011 keeps answering. Restart only when nginx is not running to reload.
    if [ "$target" = "server" ]; then
        docker compose -f "$LE_COMPOSE_FILE" exec -T core-http nginx -s reload >/dev/null 2>&1 \
            || docker compose -f "$LE_COMPOSE_FILE" restart core-http >/dev/null 2>&1 \
            || le_warn "Could not reload core-http; the new certificate is served after the next restart"
    fi
    return 0
}

# Whether a certificate file and a private key file are a pair, compared by
# their public halves. The certificate carries the public half; the key file
# carries it too, inside the private half; the pair matches exactly when those
# are equal.
#
#   le_cert_key_match CERT KEY
#
# Same reasoning as {@see \App\Lib\Ssl\KeyPair}, which is the PHP side of this
# and carries the measurements. Kept here as openssl rather than shelling into
# artisan because renewal runs from cron on the host, with no guarantee the
# engine container is up -- and it is called at exactly the moment it may not
# be.
le_cert_key_match() {
    local cert="$1" key="$2" cert_pub key_pub
    [ -f "$cert" ] && [ -f "$key" ] || return 1

    cert_pub=$(openssl x509 -in "$cert" -noout -pubkey 2>/dev/null | openssl sha256 2>/dev/null) || return 1
    key_pub=$(openssl pkey -in "$key" -pubout 2>/dev/null | openssl sha256 2>/dev/null) || return 1

    [ -n "$cert_pub" ] && [ "$cert_pub" = "$key_pub" ]
}

# Keep APP_URL in .env-core on the served name: `mcp:token:create`,
# `mcp:check` and GET /system/info all print it, and a stale one hands every
# client a URL whose host the certificate does not name. Only an address the
# engine chose itself is replaced -- the previous certificate domain, a bare
# IP, or a derived default -- so an operator's own APP_URL stays theirs.
#
#   le_update_app_url DOMAIN [PREVIOUS_DOMAIN]
le_update_app_url() {
    local domain="$1" previous="${2:-}" env_file="${LE_ENGINE_DIR}/.env-core"
    [ -f "$env_file" ] || return 0
    local current host port
    current=$(grep '^APP_URL=' "$env_file" | head -n 1 | cut -d= -f2-)
    host=$(sed -E 's#^[a-z]+://##; s#[:/].*$##' <<<"$current")
    [ "$host" = "$domain" ] && return 0
    if [ -n "$host" ] && [ "$host" != "$previous" ] && ! le_is_ipv4 "$host" && [[ "$host" != *".${LE_BASE_DOMAIN}" ]]; then
        le_warn "APP_URL=${current} is not one the engine set; leaving it. The certificate is for https://${domain}"
        return 0
    fi
    port=$(sed -nE 's#^[a-z]+://[^:/]+:([0-9]+).*#\1#p' <<<"$current")
    port="${port:-2011}"
    # In place, not `sed -i`: .env-core is bind-mounted into core as a single
    # file, and a replaced inode leaves the container reading the old one
    # until it is restarted.
    local tmp
    tmp=$(mktemp)
    if grep -q '^APP_URL=' "$env_file"; then
        sed "s#^APP_URL=.*#APP_URL=https://${domain}:${port}#" "$env_file" >"$tmp"
    else
        { cat "$env_file"; [ -n "$(tail -c1 "$env_file")" ] && echo; echo "APP_URL=https://${domain}:${port}"; } >"$tmp"
    fi
    cat "$tmp" >"$env_file"
    rm -f "$tmp"
    le_info "APP_URL set to https://${domain}:${port}"

    # Never restart core for this: it runs the queue, and a restart kills every
    # deploy in flight. php-fpm and the scheduler read .env per run; the
    # long-lived queue workers are told to finish their job and reboot.
    le_core_running || return 0
    docker compose -f "$LE_COMPOSE_FILE" exec -T core php artisan queue:restart >/dev/null 2>&1 \
        || le_warn "Could not signal the queue workers; they report the old URL until they restart"
    # A replaced inode (an earlier `sed -i`, an editor) leaves the container on the old file.
    if ! docker compose -f "$LE_COMPOSE_FILE" exec -T core grep -qx "APP_URL=https://${domain}:${port}" /var/www/html/.env 2>/dev/null; then
        le_warn "core still reads an old .env-core (its inode was replaced); it reports the old URL until core is next restarted"
    fi
}

# Every six hours: the IP lineage is short-lived (~6 days) and certbot renews
# it at a third of its lifetime, so once a day is cutting it close. The
# 90-day domain lineage is happy with anything.
le_ensure_renewal_cron() {
    mkdir -p "$LE_LOG_DIR"
    local line="0 */6 * * * root ${LE_ENGINE_DIR}/scripts/letsencrypt-renew.sh >>${LE_LOG_DIR}/renew.log 2>&1"
    if [ -f "$LE_CRON_FILE" ] && grep -qF "$line" "$LE_CRON_FILE"; then
        return 0
    fi
    cat >"$LE_CRON_FILE" <<CRON
# Renews the engine's Let's Encrypt certificates and reinstalls the served one.
# Written by scripts/letsencrypt-lib.sh; edits are overwritten.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${line}
CRON
    chmod 644 "$LE_CRON_FILE"
    le_info "Scheduled certificate renewal in ${LE_CRON_FILE}"
}

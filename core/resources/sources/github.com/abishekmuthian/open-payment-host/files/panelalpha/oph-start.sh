#!/bin/sh
# Runs as the image's `default` user in /home/default/build, before the image's
# own command. On its first start Open Payment Host writes secrets/fragmenta.json
# (random hmac and secret keys, and the admin's admin_email and
# admin_default_password, published as admin@openpaymenthost.com /
# OpenPaymentHost) and creates the admin from it. While there is no config yet,
# the binary writes one in a scratch directory instead, where it stops before
# serving (its log directory is missing there); the generated login goes into
# both of its sections and the file is moved into place, so the admin is created
# with that login. A config that exists is never touched, so a password changed
# in the app stays changed.
set -e

say() { echo "[panelalpha] open-payment-host: $*" >&2; }

app_dir=/home/default/build
config="${app_dir}/secrets/fragmenta.json"
cd "${app_dir}"

if [ ! -f "${config}" ]; then
    if [ -z "${OPH_ADMIN_EMAIL:-}" ] || [ -z "${OPH_ADMIN_PASSWORD:-}" ]; then
        say "OPH_ADMIN_EMAIL or OPH_ADMIN_PASSWORD is not in the environment; the app writes its own config"
    else
        scratch="$(mktemp -d)"
        mkdir "${scratch}/secrets"
        ( cd "${scratch}" && exec "${app_dir}/exec/open-payment-host" ) >/dev/null 2>&1 &
        pid=$!
        # It exits as soon as it fails to open its log; in case it does not,
        # it is stopped after 30 s.
        i=0
        while kill -0 "${pid}" 2>/dev/null && [ "${i}" -lt 60 ]; do
            sleep 0.5
            i=$((i + 1))
        done
        kill "${pid}" 2>/dev/null || true
        wait "${pid}" 2>/dev/null || true
        if grep -q '"admin_default_password"' "${scratch}/secrets/fragmenta.json" 2>/dev/null; then
            mkdir -p "${app_dir}/secrets"
            # The generated values are letters, digits and @.-, so they need no
            # escaping in JSON or in sed.
            sed -e "s|\"admin_email\": \"[^\"]*\"|\"admin_email\": \"${OPH_ADMIN_EMAIL}\"|g" \
                -e "s|\"admin_default_password\": \"[^\"]*\"|\"admin_default_password\": \"${OPH_ADMIN_PASSWORD}\"|g" \
                "${scratch}/secrets/fragmenta.json" > "${config}.panelalpha"
            chmod 0744 "${config}.panelalpha"
            mv "${config}.panelalpha" "${config}"
            say "wrote the first config with the generated admin login"
        else
            say "the binary wrote no config in the scratch directory; it writes its own"
        fi
        rm -rf "${scratch}"
    fi
fi

exec "$@"

#!/bin/bash
# Server secrets generated once into ~/.panelalpha (what `manage.py
# generateserverkeys` prints): ~/project is wiped on every deploy, and the keys
# encrypt data already in the database.
set -e
STORE="${HOME}/.panelalpha/psono"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/psono.env" ]; then
    db="$(openssl rand -hex 24)"
    # NaCl box keypair = raw X25519, hex encoded.
    k="$(openssl genpkey -algorithm X25519 2>/dev/null | openssl pkey -text -noout 2>/dev/null)"
    priv="$(printf '%s\n' "$k" | awk '/^priv:/{f=1;next}/^pub:/{f=0}f' | tr -d ' :\n')"
    pub="$(printf '%s\n' "$k" | awk '/^pub:/{f=1;next}f' | tr -d ' :\n')"
    [ ${#priv} -eq 64 ] && [ ${#pub} -eq 64 ] || { echo "X25519 key generation failed" >&2; exit 1; }
    # bcrypt salt: 16 random bytes in bcrypt's base64 alphabet.
    salt="\$2b\$12\$$(openssl rand -base64 16 | cut -c1-22 | tr 'A-Za-z0-9+/' './A-Za-z0-9')"
    # Single quotes: compose would interpolate the $ in the salt otherwise.
    (umask 077; cat > "${STORE}/psono.env" <<ENV
PSONO_SECRET_KEY=$(openssl rand -hex 32)
PSONO_ACTIVATION_LINK_SECRET=$(openssl rand -hex 32)
PSONO_DB_SECRET=$(openssl rand -hex 32)
PSONO_EMAIL_SECRET_SALT='${salt}'
PSONO_PRIVATE_KEY=${priv}
PSONO_PUBLIC_KEY=${pub}
POSTGRES_PASSWORD=${db}
PSONO_DATABASE_URL=postgres://psono:${db}@db:5432/psono
ENV
    )
fi
chmod 600 "${STORE}/psono.env"

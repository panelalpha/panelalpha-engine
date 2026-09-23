#!/bin/sh
# tube boot wrapper. Runs (via the image's /init) as the account uid with /data
# bind-mounted to the account's persistent ~/.panelalpha/tube.
#
#  1. Reuse the persistent upload password so POST /upload stays gated (user
#     "uploader"); without it upload is anonymous.
#  2. Render config.json with the account's public URL — tube has no BASE_URL
#     env, only config's feed.external_url drives absolute RSS/enclosure links —
#     and point everything at the persistent /data mount.
#  3. Hand the container to tube.
set -eu

# 1. Upload-auth secret (generated once by hooks/prepare.sh, 0600). Lives in the
# /data mount, never in ~/project. Exported under both spellings so the /upload
# gate holds on the published images (auth_password) and on newer builds that
# also read AUTH_PASSWORD.
if [ -f /data/secrets.env ]; then
  set -a
  . /data/secrets.env
  set +a
fi
: "${auth_password:=}"
AUTH_PASSWORD="${auth_password}"
export auth_password AUTH_PASSWORD

# 2. Inject the engine-provided public URL as feed.external_url. Empty is fine
# (tube falls back to its own hostname). All state paths point at /data.
PUBLIC_URL="${BASE_URL:-}"
POD_HOST="${PUBLIC_URL#*://}"
POD_HOST="${POD_HOST%%/*}"
: "${POD_HOST:=tube}"

cat > /data/config.json <<EOF
{
  "library": [{ "path": "/data/videos", "prefix": "" }],
  "server": {
    "host": "0.0.0.0",
    "port": 8000,
    "store_path": "/data/tube.db",
    "upload_path": "/data/uploads",
    "max_upload_size": 1073741824
  },
  "thumbnailer": { "timeout": 60, "position_from_start": 3 },
  "transcoder": { "timeout": 1800, "sizes": null },
  "feed": {
    "external_url": "${PUBLIC_URL}",
    "title": "${POD_HOST}",
    "link": "${PUBLIC_URL}/about",
    "description": "A self-hosted tube video library",
    "author": { "name": "${POD_HOST}", "email": "" },
    "copyright": ""
  },
  "copyright": { "content": "All content herein is user contributed." }
}
EOF

# 3. tube owns the container from here.
exec tube -c /data/config.json

#!/bin/bash
# Seeds Jackett's ServerConfig.json once, only if absent: an existing config
# (and any password chosen in the UI since) is never touched.
set -euo pipefail

cfg=/config/Jackett/ServerConfig.json
if [ -e "$cfg" ]; then
    echo "[jackett] $cfg exists; left alone"
    exit 0
fi
: "${JACKETT_ADMIN_PASSWORD:?}" "${JACKETT_API_KEY:?}"

# SecurityService.HashPassword(): SHA512 over UTF-16LE(password + APIKey), hex.
hash=$(printf '%s%s' "$JACKETT_ADMIN_PASSWORD" "$JACKETT_API_KEY" \
    | iconv -f UTF-8 -t UTF-16LE | sha512sum | cut -d' ' -f1)

mkdir -p /config/Jackett
# Jackett's own first-boot defaults, plus the key, the hash and the public URL
# for Torznab links. InstanceId is left null; Jackett generates it.
jq -n --arg key "$JACKETT_API_KEY" --arg hash "$hash" --arg url "${JACKETT_BASE_URL:-}" '{
  Port: 9117, LocalBindAddress: "127.0.0.1", AllowExternal: true, AllowCORS: false,
  APIKey: $key, AdminPassword: $hash, InstanceId: null, BlackholeDir: null,
  UpdateDisabled: true, UpdatePrerelease: false, BasePathOverride: null,
  BaseUrlOverride: (if $url == "" then null else $url end),
  CacheEnabled: true, CacheTtl: 2100, CacheMaxResultsPerIndexer: 1000,
  FlareSolverrUrl: null, FlareSolverrMaxTimeout: 55000, OmdbApiKey: null, OmdbApiUrl: null,
  ProxyType: 0, ProxyUrl: null, ProxyPort: null, ProxyUsername: null, ProxyPassword: null
}' > "$cfg.tmp"
mv "$cfg.tmp" "$cfg"
# The app's own init (lsiown) hands /config to its runtime user on start.
echo "[jackett] seeded $cfg with an admin password"

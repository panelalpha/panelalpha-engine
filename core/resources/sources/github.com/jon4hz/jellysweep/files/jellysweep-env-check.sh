#!/bin/sh
# Jellysweep refuses to start without Jellyfin, Sonarr, Radarr and Jellystat or Streamystats.
missing=""
need() { eval "v=\${$1:-}"; [ -n "$v" ] || missing="$missing $1"; }
need JELLYSWEEP_JELLYFIN_URL
need JELLYSWEEP_JELLYFIN_API_KEY
# The upstream README says Sonarr OR Radarr, but the config defaults
# (sonarr.unmonitor, radarr.unmonitor) make both sections exist, so both are validated.
need JELLYSWEEP_SONARR_URL
need JELLYSWEEP_SONARR_API_KEY
need JELLYSWEEP_RADARR_URL
need JELLYSWEEP_RADARR_API_KEY
if [ -z "${JELLYSWEEP_JELLYSTAT_URL:-}${JELLYSWEEP_STREAMYSTATS_URL:-}" ]; then
    missing="$missing JELLYSWEEP_JELLYSTAT_URL+JELLYSWEEP_JELLYSTAT_API_KEY(or JELLYSWEEP_STREAMYSTATS_URL+JELLYSWEEP_STREAMYSTATS_SERVER_ID)"
fi
[ -z "${JELLYSWEEP_JELLYSTAT_URL:-}" ] || need JELLYSWEEP_JELLYSTAT_API_KEY
[ -z "${JELLYSWEEP_STREAMYSTATS_URL:-}" ] || need JELLYSWEEP_STREAMYSTATS_SERVER_ID
if [ -n "$missing" ]; then
    echo "jellysweep: missing project environment variable(s):$missing. Set them and redeploy." >&2
    exit 1
fi
echo "jellysweep: required settings present"

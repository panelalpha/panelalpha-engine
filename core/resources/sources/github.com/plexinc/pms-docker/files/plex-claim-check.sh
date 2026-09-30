#!/bin/sh
# Passes once Plex reports itself claimed (signed in to a Plex account).
# The image's first-run step exchanges PLEX_CLAIM for a server token.
for _ in $(seq 1 15); do
    if curl -fsS http://pms:32400/identity | grep -q 'claimed="1"'; then
        echo "plex: server is claimed"
        exit 0
    fi
    sleep 2
done

if [ -z "${PLEX_CLAIM:-}" ]; then
    echo "plex: the server is not claimed. Get a claim token at https://plex.tv/claim (valid 4 minutes), set it as the project's PLEX_CLAIM environment variable and redeploy." >&2
else
    echo "plex: plex.tv did not accept PLEX_CLAIM (claim tokens expire after 4 minutes and work once). Get a fresh one at https://plex.tv/claim, update PLEX_CLAIM and redeploy." >&2
fi
exit 1

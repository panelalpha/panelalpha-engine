#!/bin/bash
# ~/.panelalpha is private to the account, so the app container is given only
# ~/.panelalpha/spip (overrides/docker-compose.override.yml). Create it here, as
# the account, before compose binds it: a missing bind source would be made by
# Docker as root, and SPIP (running as the account) could not write into it.
set -e
mkdir -p "${HOME}/.panelalpha/spip"

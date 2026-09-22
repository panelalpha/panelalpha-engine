# The upstream repo ships no Dockerfile -- the production image is built in the
# separate docker-inventaire repo (server + separate inventaire-client, both
# built) and published as inventaire/inventaire:latest, which already contains
# the built client. A source recipe cannot use the compose strategy (engine#221),
# so the app service is this one-line image and the datastores are added by
# overrides/docker-compose.override.yml. The image keeps its own ENTRYPOINT
# (docker-entrypoint.sh: writes dist/config/local.js from env, execs the server);
# extends: dockerfile builds this file as-is and does not replace it.
FROM inventaire/inventaire:latest

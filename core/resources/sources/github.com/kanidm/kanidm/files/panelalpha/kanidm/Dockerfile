# The `recover` service's image: upstream's tools image (bash, curl, the kanidm
# CLI) plus kanidmd from the server image, for `kanidmd scripting
# recover-account` over the admin socket on the data volume. The server image
# has no shell; kanidmd needs only glibc, which the tools image has.
FROM docker.io/kanidm/server:1.12.1 AS server

FROM docker.io/kanidm/tools:1.12.1
COPY --from=server /sbin/kanidmd /usr/local/sbin/kanidmd

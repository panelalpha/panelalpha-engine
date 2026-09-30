# Tools image for the one-shot `init` service only; the app runs the published
# image unchanged. The upstream image is distroless (no shell), so the static
# note-mark binary is copied into alpine to seed the owner idempotently.
ARG NOTEMARK_IMAGE=ghcr.io/enchant97/note-mark:1.1
FROM ${NOTEMARK_IMAGE} AS upstream

FROM docker.io/library/alpine:3
COPY --from=upstream /note-mark /usr/local/bin/note-mark
COPY init.sh /init.sh
ENTRYPOINT ["/bin/sh", "/init.sh"]

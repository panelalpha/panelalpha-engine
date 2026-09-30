# Tools image for the one-shot `init` service only; `app` runs the published
# image unchanged. Adds curl + jq to drive Shiori's own REST API.
ARG SHIORI_IMAGE=ghcr.io/go-shiori/shiori:alpine-v1.8.0
FROM ${SHIORI_IMAGE}
RUN apk add --no-cache curl jq
COPY init.sh /init.sh
ENTRYPOINT ["/bin/sh", "/init.sh"]

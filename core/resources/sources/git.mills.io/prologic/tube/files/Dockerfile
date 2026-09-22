# tube: run the official published image, not a from-source rebuild.
#
# The repository's own Dockerfile compiles the Go binary (golang:alpine,
# ffmpeg-dev, `make server`); the project publishes that exact build as an
# official multi-arch image with the ffmpeg transcoding toolchain baked in, so
# this pulls it instead of building anything. Pinned by digest to the tag
# `latest` as published on Docker Hub 2024-12-24 for a reproducible deploy.
FROM prologic/tube@sha256:ce8c283efba859252b95baf0d8be079f26c722c1583a354b4d225692849dd167

# Boot wrapper: reuse the persistent upload password and render config.json with
# the account's public URL before handing the container to tube. Copied from
# ~/project (the recipe ships it as files/pa-run.sh) and made executable.
USER root
COPY pa-run.sh /usr/local/bin/pa-run.sh
RUN chmod +x /usr/local/bin/pa-run.sh

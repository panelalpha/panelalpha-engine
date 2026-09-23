# yarnd: run the official published release, not a from-source rebuild.
#
# The repository's own Dockerfile compiles the Go binary (golang:alpine,
# ffmpeg-dev, `make server`). The project publishes that exact release as an
# official multi-arch image, so this pulls it instead of building anything —
# no toolchain, no compile. Pinned to 0.16.0 (Docker Hub, 2026-07-12) for a
# reproducible deploy; `latest` was that same digest at authoring time.
#
# The only addition is curl, used once at boot by pa-run.sh to seed the admin
# through yarnd's own gated /setup wizard on localhost. That is provisioning an
# ops tool into the runtime image, not a patch to the application.
FROM prologic/yarnd:0.16.0

USER root
RUN apk add --no-cache curl

# Boot wrapper: reuse persistent secrets, seed the owner admin before the port
# is public, then hand the container to yarnd. Copied from ~/project (the
# recipe ships it as files/pa-run.sh) and made executable.
COPY pa-run.sh /usr/local/bin/pa-run.sh
RUN chmod +x /usr/local/bin/pa-run.sh

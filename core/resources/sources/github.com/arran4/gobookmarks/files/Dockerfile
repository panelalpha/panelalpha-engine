# gobookmarks' own root Dockerfile is the release image: it COPYs a binary
# GoReleaser builds in CI, which the checkout does not contain, so building it
# fails at the COPY. The project publishes a working image; run it directly.
# Inherits ENTRYPOINT ["gobookmarks"], CMD ["serve"] and EXPOSE 8080.
FROM ghcr.io/arran4/gobookmarks:latest

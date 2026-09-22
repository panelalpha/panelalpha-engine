# Continuwuity, run from the official release image.
#
# The release image is `FROM scratch` (one binary at /sbin/conduwuit, no
# shell), so it cannot run an entrypoint that turns the platform's injected
# public host into the CONTINUWUITY_* environment the server reads. A static
# busybox is copied in only to run that wrapper; the Matrix server binary is
# upstream's and is not rebuilt or patched. This is configuration, not repair -
# no Rust build runs here.
FROM busybox:1.37.0-musl AS busybox

FROM forgejo.ellis.link/continuwuation/continuwuity:latest

# One static binary; the wrapper calls its applets as `/bin/busybox <applet>`,
# so nothing else in the image is touched.
COPY --from=busybox /bin/busybox /bin/busybox
COPY panelalpha-entrypoint.sh /panelalpha-entrypoint.sh

EXPOSE 8008
ENTRYPOINT ["/bin/busybox", "sh", "/panelalpha-entrypoint.sh"]

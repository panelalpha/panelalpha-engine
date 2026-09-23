# Crafty Controller, run from the official upstream image.
#
# The upstream image (registry.gitlab.com/crafty-controller/crafty-4) bakes in
# the Python venv and five OpenJDK runtimes; building the repo's own Dockerfile
# would recompile all of that. We start FROM it and change nothing about Crafty.
#
# The only thing added is a stunnel TLS bridge. Crafty serves HTTPS only, on
# :8443; the engine's proxy forwards plain HTTP. stunnel accepts that plain HTTP
# on :8000 and forwards it as TLS to Crafty on 127.0.0.1:8443. This is
# configuration in front of the untouched image, not a repair of it.
FROM registry.gitlab.com/crafty-controller/crafty-4:latest

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends stunnel4 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY panelalpha-stunnel.conf /etc/stunnel/panelalpha.conf
COPY panelalpha-entrypoint.sh /usr/local/bin/panelalpha-entrypoint.sh
RUN chmod +x /usr/local/bin/panelalpha-entrypoint.sh

# The plain-HTTP port the engine's proxy forwards to (in front of Crafty's 8443).
EXPOSE 8000

# Start the bridge, then hand off to Crafty's own launcher with its default args.
ENTRYPOINT ["/usr/local/bin/panelalpha-entrypoint.sh"]
CMD ["-d", "-i"]

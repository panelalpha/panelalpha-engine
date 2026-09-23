# SCM-Manager, run from the official upstream image (by Cloudogu).
#
# Building the Gradle/Java project from source is heavy and unnecessary: upstream
# publishes a ready image. We start FROM the pinned tag and change nothing about
# the SCM-Manager server. Only two configuration files are added:
#
#   * panelalpha-config.yml -> /etc/scm/config.yml: serves the app at / instead
#     of upstream's /scm (so the bare public domain works) and turns on
#     forwardHeadersEnabled so links/redirects follow the account's public HTTPS
#     domain behind the engine's proxy. It is the upstream config with those two
#     lines changed - configuration, not a repair.
#   * panelalpha-entrypoint.sh: seeds the first admin non-interactively on a
#     fresh volume, then starts the upstream server unmodified.
FROM scmmanager/scm-manager:3.12.1

USER root
COPY panelalpha-config.yml /etc/scm/config.yml
COPY panelalpha-entrypoint.sh /panelalpha-entrypoint.sh
RUN chmod 0644 /etc/scm/config.yml && chmod 0755 /panelalpha-entrypoint.sh
USER 1000

EXPOSE 8080
ENTRYPOINT ["/panelalpha-entrypoint.sh"]

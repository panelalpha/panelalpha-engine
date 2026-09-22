# The sshd WeTTY connects to.
#
# WeTTY does not authenticate anybody and never has: every route it serves is
# public (src/server/socketServer.ts:30-50), and the only credential in the
# product is the one the sshd at the far end asks for. So the far end is the
# whole of the authentication, and it is this image.
#
# The repository's own far end is containers/ssh/Dockerfile:
#
#     FROM sickp/alpine-sshd:latest
#     RUN adduser -D -h /home/term -s /bin/sh term && \
#         ( echo "term:term" | chpasswd )
#
# -- a shared password published in a public git repository. Measured on a
# stock deploy of this repository, an anonymous socket.io client typed `term`
# and `term` and got `uid=1000(term) gid=1000(term)`. This image exists so the
# recipe never has to ship that.
#
# What is different here:
#
#   * one login, the customer's own account name, at the account's own uid;
#   * its password is read at start from a 0600 file in the account's home and
#     is never in an image layer, an environment variable or a compose file;
#   * empty passwords refused, root refused, forwarding of every kind refused;
#   * the only thing mounted is the account's home.
FROM alpine:3.22

# A usable shell, and nothing that talks to a network -- there is no network:
# the compose file puts this container alone on an `internal: true` bridge.
# git is here because git works on a local checkout, which is most of what
# anyone wants a terminal on a hosting account for.
RUN apk add --no-cache \
      openssh-server \
      bash \
      shadow \
      coreutils \
      findutils \
      grep \
      sed \
      less \
      nano \
      tar \
      gzip \
      unzip \
      git \
      tzdata

# Written as a file rather than assembled by the entrypoint so that what sshd
# was told is readable in the recipe. AllowUsers is appended at start, because
# the account name is not knowable here.
RUN printf '%s\n' \
    'Port 22' \
    'AddressFamily inet' \
    'ListenAddress 0.0.0.0' \
    'PermitRootLogin no' \
    'PasswordAuthentication yes' \
    'PermitEmptyPasswords no' \
    'KbdInteractiveAuthentication no' \
    'PubkeyAuthentication yes' \
    'AuthorizedKeysFile /account/.panelalpha/wetty/authorized_keys' \
    'X11Forwarding no' \
    'AllowTcpForwarding no' \
    'AllowAgentForwarding no' \
    'AllowStreamLocalForwarding no' \
    'GatewayPorts no' \
    'PermitTunnel no' \
    'PermitUserEnvironment no' \
    'MaxAuthTries 3' \
    'MaxSessions 8' \
    'LoginGraceTime 30' \
    'ClientAliveInterval 300' \
    'ClientAliveCountMax 2' \
    'PrintMotd no' \
    'Subsystem sftp internal-sftp' \
    > /etc/ssh/sshd_config

COPY wetty-sshd-entrypoint.sh /usr/local/bin/wetty-sshd-entrypoint
RUN chmod 0755 /usr/local/bin/wetty-sshd-entrypoint

EXPOSE 22
ENTRYPOINT ["/usr/local/bin/wetty-sshd-entrypoint"]

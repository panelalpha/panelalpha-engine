#!/bin/bash
# Account shell, after the clone and after overrides/docker-compose.yml has been
# written into ~/project, before detection and the build.
#
# Nothing here is built. docker/Dockerfile's payload is a release .deb
# (`COPY ${PACKAGE}_${TARGETARCH}.deb /`) that is produced elsewhere and is not
# in the checkout, so the tree cannot build its own image; upstream publishes
# sutoj/piler on Docker Hub instead. What this hook supplies is the image tag,
# taken from the checkout, and this account's credentials, kept where the next
# clone will not delete them.
set -e
cd ~/project

say() { echo "[piler] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL and a fork answers to the same one. The
# compose file below runs a published image pinned from VERSION, and the setup
# service matches password hashes that come from util/db-mysql.sql. Saying so
# here turns a puzzling runtime failure into one line in the deploy log.
if [ ! -f VERSION ] || [ ! -d webui ] || [ ! -f docker/docker-compose.yaml ]; then
    say "WARNING: this does not look like the jsuto/piler layout (VERSION, webui/, docker/ expected)"
fi

# ---------------------------------------------------------------------------
# 1. The image tag, from the checkout.
#
# VERSION is a single line, `1.4.9`, and is the one version string in the tree
# that means the product (configure.in reads it too). Docker Hub publishes it
# unprefixed -- 1.4.9 was pushed 2026-06-17 -- but only for releases, so a clone
# of master between two of them carries a version with no image yet.
#
# Confirmed published before it is used, because a tag whose pull fails takes
# the whole deploy with it. The fallback is `latest`, which upstream moves with
# each release.
PILER_VERSION=$(tr -d '[:space:]' < VERSION 2>/dev/null)
PILER_TAG=latest
if [ -n "${PILER_VERSION}" ] && curl -fsS --max-time 15 -o /dev/null \
    "https://hub.docker.com/v2/repositories/sutoj/piler/tags/${PILER_VERSION}" 2>/dev/null; then
    PILER_TAG="${PILER_VERSION}"
fi
say "using sutoj/piler:${PILER_TAG}"

# ---------------------------------------------------------------------------
# 2. The secrets, outside the checkout.
#
# engine#173: every deploy empties ~/project before the clone, so a guard on a
# file in there never fires on a redeploy -- the database password would be
# regenerated while db_data still held the old one, and the admin password would
# be regenerated as one nobody was ever told while the user row kept the old
# hash. And ProjectEnvironment::apply() copies ~/project/.env to .env.default at
# mode 644 inside a home that is root-owned 0755, which makes anything written
# there readable by every other account's uid on this host.
#
# So everything generated here lives in ~/.panelalpha/ at 0600 in a 0700
# directory, and that file is also the stack's second env_file -- compose reads
# it at ../.panelalpha/piler.env, relative to the --project-directory the engine
# passes. No secret is ever written into ~/project.
STORE_DIR="${HOME}/.panelalpha"
STORE="${STORE_DIR}/piler.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${STORE_DIR}" 2>/dev/null || true

if [ ! -f "${STORE}" ]; then
    # Alphanumeric, no punctuation: MYSQL_PASSWORD is sed'd into piler.conf and
    # into config-site.php as a single-quoted PHP literal by the image's
    # start.sh (`s/verystrongpassword/${MYSQL_PASSWORD}/g`), where a '/' would
    # end the sed expression and a quote would end the literal. The admin
    # password is additionally typed by a human.
    umask 077
    cat > "${STORE}" <<EOF
# Written by PanelAlpha on the first deploy of this account, and reused by every
# redeploy. This file is the compose stack's env_file; nothing in it is ever
# copied into ~/project, because the engine republishes ~/project/.env as a
# world-readable .env.default (engine#173).
#
# The database account Piler connects with. Read by the mariadb image to create
# it, and by the piler image's start.sh to write piler.conf, config-site.php and
# /etc/piler/.my.cnf. The password is also inside the db_data volume, so
# changing it here alone locks Piler out of its own archive.
MYSQL_DATABASE=piler
MYSQL_USER=piler
MYSQL_PASSWORD=$(openssl rand -hex 24)
# The administrator of the web UI.
#
# Piler ships a fixed one: util/db-mysql.sql inserts admin@local with the
# md5-crypt hash of 'pilerrocks' and auditor@local with the hash of 'auditor',
# both constants in a public repository, and
# webui/model/user/auth.php::checkFallbackLogin() authenticates against them
# with a plain crypt() comparison. There is no sign-up page and no first-run
# wizard, so the shipped rows are replaced by files/panelalpha-setup.sh with
# this password rather than left open.
PILER_ADMIN_USERNAME=admin@local
PILER_ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-20)
EOF
    chmod 600 "${STORE}"
    say "generated this account's database and administrator credentials in ~/.panelalpha/piler.env"
else
    say "reusing the credentials already in ~/.panelalpha/piler.env"
fi

# ---------------------------------------------------------------------------
# 3. ~/project/.env -- the file compose interpolates, and nothing else.
#
# Rewritten on every deploy rather than guarded, because everything in it is
# derived and none of it is secret, so the engine republishing it at 644 is a
# non-event.
cat > .env <<EOF
# Written by PanelAlpha. Compose reads this for \${...} substitution in
# docker-compose.yml. Nothing secret belongs here: the engine copies this file
# to .env.default at mode 644 (engine#173). This account's credentials are in
# ~/.panelalpha/piler.env, 0600.
PILER_IMAGE=sutoj/piler:${PILER_TAG}
PILER_DB_IMAGE=mariadb:12.0.2
PILER_MANTICORE_IMAGE=manticoresearch/manticore:25.0.0
PILER_MEMCACHED_IMAGE=memcached:1.6-alpine
EOF
chmod 600 .env

# ---------------------------------------------------------------------------
# 4. Where a human is pointed. Beside the credentials it describes, not in
# ~/project, which the next deploy deletes.
cat > "${STORE_DIR}/piler-credentials.txt" <<'EOF'
Piler (jsuto/piler), deployed by PanelAlpha.

Piler is an email archive. Sign in at your account's domain with:

  Username   admin@local          (this is a Piler login name, not a mailbox)
  Password   see PILER_ADMIN_PASSWORD in ~/.panelalpha/piler.env

Change it under Settings once you are in; this file and piler.env are not
updated when you do, and nothing here will overwrite your new password.

FIRST THING TO KNOW: the administrator can SEARCH but cannot OPEN messages.

That is Piler's design, not a fault of this installation. Read access is granted
by email address or by the auditor role, and admin@local is the address of
nothing, so message bodies come back "no permission". To read the archive:

  Domains -> add the domain whose mail you are archiving.
  Users   -> add a user whose email addresses are the archived mailbox's.
             That user sees and opens their own mail.
  Users   -> or add a user with the Auditor role, who can read everything in
             the domains they are granted. (The built-in auditor@local was
             locked on the first deploy -- Piler ships it with the password
             'auditor', published in the source.)

HOW MAIL GETS IN

Piler's headline feature is a built-in SMTP receiver on port 25, and this
deployment deliberately does not publish it: on shared hosting a tenant
application does not own inbound SMTP for the account's domain. Piler's pull
paths are used instead, and they are first-class -- src/import_imap.c,
src/import_pop3.c and util/imapfetch.py are upstream's own code, driven from
the web UI.

  Web UI, IMAP/POP3 pull:
      Import -> add a job with the mailbox server, username and password, and
      use "test connection" to check it. util/import.sh runs imapfetch.py from
      cron every five minutes and archives what it finds. Office 365 and Gmail
      OAuth helpers are in contrib/o365 and util/get-token.py.

      A mailbox server with a self-signed certificate fails with "SSL peer
      certificate ... was not OK" until verifyssl=0 is set in
      /etc/piler/piler.conf. Prefer fixing the certificate.

      Note that the mailbox password you type there is stored in Piler's own
      database in the clear. Give Piler a restricted account on the mailbox
      server rather than the user's own password.

  Bulk import of existing mail, from the account shell:
      cd ~/project
      docker compose cp /path/to/message.eml piler:/tmp/message.eml
      docker compose exec --workdir /var/piler/tmp piler \
          pilerimport -e /tmp/message.eml

      --workdir matters: pilerimport writes temporary files into the current
      directory and exits "cannot write current directory!" without it.

      pilerimport also takes -m <mbox>, -d <directory of .eml> and
      -K <pop3 server>; `docker compose exec piler pilerimport -h` lists
      everything. Its -i <imap server> mode does not work in 1.4.9 -- it
      downloads and then stores nothing -- so use the Import page for IMAP.

  Relaying from your own MTA:
      Port 25 is open inside the stack, on the `piler` service. An MTA you run
      elsewhere can be pointed at it if you publish the port yourself, and
      etc/smtp.acl.example shows how Piler restricts who may deliver. Read
      docs/ first; opening 25 to the internet makes this an open relay target.

WHAT IS NOT SET UP

  Outbound mail. Daily reports, automated searches and "restore to mailbox"
  need an SMTP server; set SMTP_FROMADDR and its companions in
  /etc/piler/config-site.php inside the piler container.

  TLS between Piler and your mailbox server is Piler's own; TLS for the web UI
  is terminated by the hosting proxy on your account's certificate.

RETENTION AND DISK

  Archives grow and do not shrink by themselves. Policies -> Retention sets how
  long messages are kept, and util/purge.sh runs nightly from cron to apply it.
  With no policy, nothing is ever deleted.

THE SHIPPED ACCOUNTS

  Piler's schema seeds admin@local / pilerrocks and auditor@local / auditor,
  both published in the source. Both were replaced on the first deploy: the
  admin with the password above, the auditor with a random one that is not kept
  anywhere. If you want an auditor, set a password for it under Users.
EOF
chmod 600 "${STORE_DIR}/piler-credentials.txt"

say "credentials and notes in ~/.panelalpha/piler-credentials.txt"

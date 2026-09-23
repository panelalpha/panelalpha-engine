# Piler

Tracker: [supported-apps#1073](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/1073)
· Upstream: <https://github.com/jsuto/piler> (`VERSION` 1.4.9, GPL v3)

---

Piler is an email archive. Messages arrive, are deduplicated, compressed,
encrypted and written to a content-addressed store; their metadata goes into
MySQL and their full text into a Manticore index; and a PHP web interface
searches, previews, exports and restores them. It is a compliance product —
retention policies, legal hold, an audit trail, four-eyes authorisation — not a
webmail.

The repository is the whole thing: `src/` is the C daemon set, `webui/` is the
PHP interface, `util/db-mysql.sql` is the schema, and autotools ties it
together. What the repository is *not* is buildable here — see below.

## What the detector saw, and why it was not enough

Detection settled on `strategy: php` and served a 403: there is no `index.php`
at the repository root, and `webui/` is not in `PhpDocroot`'s candidate list.
Pointing `docroot` at `webui` would have produced a rendered login page, which
is exactly the trap the Bluecherry recipe describes. The PHP interface is a
console over three things it does not contain:

* `/usr/bin/pilerget` and `/usr/bin/pileraget`, the C binaries that decrypt a
  stored message body and its attachments. `webui/model/search/message.php`
  shells out to them; without them a message can be listed and never read.
* the MySQL schema in `util/db-mysql.sql`, which holds every piece of metadata
  the UI paginates over.
* a Manticore instance. Piler does not search MySQL. `webui/model/search/`
  issues SphinxQL against ports 9306 and 9307, and the C side writes the index
  through the same protocol, so the *only* searchable copy of every subject and
  body lives there.

Served on its own, `webui/` is a search page over nothing, wired to a database
that does not exist.

## It does not need to be built

`docker/Dockerfile` is in the tree and is a red herring. Its payload is

```
COPY ${PACKAGE}_${TARGETARCH}.deb /
```

— a release `.deb` produced elsewhere and absent from the checkout, which
`docker/README.md` tells you to fetch from GitHub releases by hand. The tree
cannot build its own image even with the Dockerfile in front of it.

Upstream publishes `sutoj/piler` on Docker Hub instead: 13 tags, `1.4.9` pushed
2026-06-17, matching this `VERSION`. `hooks/prepare.sh` reads `VERSION`,
confirms the tag exists on Docker Hub and falls back to `:latest` when a clone
of master sits between releases. Nothing is compiled during a deploy, so
engine#184 (the host build container taking hostRAM/3) does not apply.

## Port 25: the reason this was nearly Rejected, and the reason it is not

Piler's headline feature is a built-in SMTP receiver. `docker/docker-compose.yaml`
publishes `"25:25"` and the image `EXPOSE`s 25, 80 and 443, because upstream
expects Piler to be a mail destination — usually an MTA's `always_bcc` target.

A tenant application does not own inbound SMTP for the account's domain on
shared hosting, so **the `25:25` publish is dropped**. `piler-smtp` is still
listening on 25 inside the container and anything on the account's own compose
network could deliver to it; nothing outside the account can reach it.

That is not a crippled deployment, because Piler's pull paths are upstream's own
first-class code rather than a workaround:

| Path | Where it lives |
| --- | --- |
| IMAP pull | `src/import_imap.c`, `src/imap.c`, `util/imapfetch.py`, `util/download-imap.php` |
| POP3 pull | `src/import_pop3.c`, `src/pop3.c` |
| Bulk EML / mbox / Maildir | `src/pilerimport.c` → `/usr/bin/pilerimport` |
| Office 365, Gmail | `contrib/o365`, `util/gmail-imap-import.php`, `util/get-token.py` |

and the web interface has an **Import** page (`webui/controller/import/`) that
stores mailbox jobs with a "test connection" button, which `util/import.sh` runs
through `imapfetch.py` from the container's cron every five minutes. An archive
that pulls from the mailboxes it archives is the complete product. Unlike Sympa
(#1084), Piler does not have to *be* the domain's mail destination to work.

## The stack

`overrides/docker-compose.yml` is `docker/docker-compose.yaml` adapted for one
account. All four of upstream's services are kept.

```
piler ──┬── db         (MariaDB)      metadata, users, policies
        ├── manticore  (searchd)      the only searchable copy of subjects/bodies
        └── memcached                 sessions and user preferences
```

What changed, and why:

**`container_name:` dropped from all four.** Upstream pins them to `piler`,
`mysql`, `manticore` and `memcached`. Those are global names on a Docker daemon
and collide with anything else the account runs — `container_name: mysql` in
particular is a name the next stack will want. Compose's own project-prefixed
names are unique, and what the services address each other by is the *service*
name, which is unchanged.

**`MYSQL_PASSWORD=piler123` replaced.** Upstream ships a literal in a public
repository. `hooks/prepare.sh` generates one per account.

**Sizes.** Upstream reserves and caps 512M each for `piler` and `manticore`,
sets `rt_mem_limit = 512M` on the `piler1` index, and gives MariaDB a 256M
buffer pool through `docker/piler.cnf`. Those are numbers for a dedicated
archive host. See *Memory* below.

**A readiness gate and a setup service.** See below.

## Four things the engine cannot infer

### 1. `PILER_HOSTNAME`

`docker/start.sh`'s `pre_flight_check` aborts the container unless
`PILER_HOSTNAME` is set, and it is not a URL — it is a bare hostname that
becomes `hostid=` in `piler.conf`, `server_name` in the nginx vhost and
`SITE_NAME_CONST` in `config-site.php`.

It does not match `ComposePlaceholders::PUBLIC_URL_KEY_PATTERN`
(`/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i`, `ComposePlaceholders.php:82`), so the
compose file carries `PA_PUBLIC_URL: http://localhost`, which *does* match and
is rewritten to the account's https address, and `files/piler-entrypoint.sh`
splits the hostname out of it before exec'ing the image's own `/start.sh`. This
is the same split the Seafile recipe makes for `SEAFILE_SERVER_HOSTNAME`.

### 2. `SITE_URL`

The same value one layer up, and still wrong once the hostname is right.
`etc/config-site.dist.php` hardcodes

```php
$config['SITE_URL'] = 'http://' . $config[SITE_NAME_CONST] . $config['PATH_PREFIX'];
```

and `start.sh` only substitutes the hostname into it. Every redirect Piler
issues is an absolute `Location:` built from that value —
`controller/login/login.php:89`, `index.php:102`, `controller/user/settings.php`,
twenty more — so on an account whose TLS is terminated at the engine's proxy the
browser is bounced to `http://` after every sign-in, save and logout.

`config-site.php` is generated on the first boot *inside the `piler_etc`
volume*, not in the checkout, which is why `files/panelalpha-setup.sh` asserts
it there rather than `hooks/prepare.sh` writing it into a directory the next
deploy deletes. The line is marked and rewritten in place on redeploy, so a
domain change does not leave a stale assignment further down the file.

### 3. The administrator

`util/db-mysql.sql:229-230`:

```sql
insert into `user` (uid, username, ..., password, isadmin, domain) values
  (0, 'admin',   ..., '$1$PItc7d$zsUgON3JRrbdGS11t9JQW1', 1, 'local');
  (1, 'auditor', ..., '$1$SLIIIS$JMBwGqQg4lIir2P2YU1y.0', 2, 'local');
```

with `admin@local` and `auditor@local` in the `email` table. Those are md5-crypt
hashes of `pilerrocks` and `auditor`, and
`webui/model/user/auth.php:97` authenticates against them with a plain
`crypt($password, $stored)` comparison *before* it tries LDAP or IMAP.

Piler has no sign-up page and no first-run wizard, so there is nothing for an
unauthenticated caller to **claim**. What there is, is a working administrator
login on a public HTTPS name for anybody who has read the repository — and
`isadmin=2` on the second row is Piler's auditor role, which can search and read
every archived message in every domain it is granted.

`files/panelalpha-setup.sh` replaces both rows, each `UPDATE` conditioned on the
hash still being exactly the shipped one, so it is idempotent across redeploys
and never overwrites a password the customer has since chosen in Settings. The
admin gets the generated password; the auditor gets one that is kept nowhere, so
an operator who wants an auditor has to set a password for it deliberately. The
hashes are computed with the image's own PHP in the format
`webui/system/misc.php`'s `encrypt_password()` writes (`$6$rounds=5000$…`,
SHA-512) rather than the md5-crypt upstream seeds, so the replacement is also an
upgrade.

The script then signs in with the generated password and checks for the
administrator's redirect (`index.php?route=health/health`), because a rendered
login page proves nothing about the row behind it.

`docker/start.sh` does offer `ADMIN_USER_PASSWORD_HASH`, which it applies as
`update user set password=… where uid=0` inside `init_database()` — before nginx
starts, so the shipped hash would never be reachable over HTTP at all. It is
deliberately **not** used: the guard is `[[ -v … ]]`, so it re-asserts on every
container restart and would silently revert a password the customer changed in
the UI. The conditional `UPDATE` after the healthcheck is the trade: a few
seconds during the first boot in which the shipped credential is live on a port
that is published inside the account but not yet proxied to the account's
domain, in exchange for a password change that survives a restart.

### 4. The sizes

Measured on a deployed account with the archive loaded and idle, three messages
in it:

| Service | Idle | Cap set here | Upstream |
| --- | --- | --- | --- |
| `piler` | 39–51 MiB | 640m | 512M reserved *and* capped |
| `db` (MariaDB) | 104–106 MiB | 384m | uncapped, 256M buffer pool |
| `manticore` | 23–30 MiB | 448m | 512M reserved *and* capped |
| `memcached` | 3.5 MiB | 96m | uncapped, `-m 64` |
| **total** | **≈ 170–190 MiB** | **1568m** | — |

`setup` (128m) and `ready` (32m) exist only during `up -d`, so the peak the
account has to hold is 1728m against a 2000 MB limit.

Manticore is the number the triage note flagged, and it is the one that was
most over-provisioned: 512M reserved for a process using 30 MiB with an empty
index. The reservation follows `rt_mem_limit`, which
`files/manticore.conf` brings from 512M to 128M — that is the RAM chunk size
before searchd flushes to a disk chunk, so it trades flush frequency for
footprint and costs neither capacity nor correctness. MariaDB, not Manticore,
is the largest resident of this stack.

The caps are headroom rather than measurements: `piler` holds a message in
memory while `pilerimport` indexes it and runs a php-fpm pool behind the search
UI, and an archive with a hundred thousand messages in it was not tested here.

## Credentials

Everything generated lives in `~/.panelalpha/piler.env` (0600, in a 0700
directory `hooks/prepare.sh` creates — an account's home is root-owned 0755) and
nowhere else. That file is also the stack's `env_file`; compose reads it at
`../.panelalpha/piler.env`, relative to the `--project-directory` the engine
passes. `~/project/.env` holds four image tags.

Two engine behaviours make that the shape rather than a preference:

* **engine#173** — every deploy empties `~/project` before the clone, so a guard
  on a file in there never fires on a redeploy. A regenerated `MYSQL_PASSWORD`
  would lock Piler out of an archive it can no longer decrypt.
* **engine#173** again — `.env` is republished as `.env.default` at mode 644
  inside a world-traversable home, which makes anything written there readable
  by every other account's uid on the host.

`~/.panelalpha/piler-credentials.txt` is where a human is pointed: the login, how
to get mail in by all three routes, what is not configured, and the retention
warning.

## What must survive together

Four named volumes, and they are not independent:

| Volume | Holds |
| --- | --- |
| `piler_etc` | `piler.key`, `piler.pem`, `piler.conf`, `config-site.php` |
| `piler_store` | the encrypted message store |
| `db_data` | the metadata that points into the store |
| `piler_manticore` | the index over it |

`piler.key` is 56 bytes from `/dev/urandom`, generated by `start.sh` on the
account's first boot, and it is what every archived message body is encrypted
with. **Lose it and the archive is unreadable — there is no recovery path.**

That is also the answer to engine#175: the one value that would otherwise
collide across tenants is generated per account into that account's own named
volume, not derived from `__DIR__`, `realpath()` or `DOCUMENT_ROOT`. The
checkout is not bind-mounted into any container, so the uniform `/app` mount
never comes into it.

## Readiness

engine#90: the engine runs `docker compose up -d` without `--wait` and takes the
deploy to be finished when that returns, which is when the containers have been
*started*. Here that gap is the whole first boot — MariaDB initialising a data
directory, Manticore opening four empty RT indexes, then `start.sh` generating
`piler.key`, writing its configs, loading a 40-table schema and only then
starting nginx.

`ready` (`alpine:3`, `exit 0`) waits for `setup`, which waits for the `piler`
healthcheck. So `up -d` returns only once the login page answers, the two
published credentials are gone and the generated one has signed in.

The healthcheck is **not** upstream's `curl -s smtp://localhost/`, which probes
the SMTP receiver this deployment does not publish and says nothing about the
web UI. It asks nginx for the front page and greps for the login form's own
`id="loginpage"`, so a PHP fatal rendered with a 200 — the shape engine#185's
platform-wide `display_errors=1` tends to produce — does not pass.

## Security notes

Beyond the shipped credentials above:

* **`searchd` has no authentication of any kind**, and the index holds every
  archived message's sender, recipients, subject and body. The `manticore`
  service publishes no host port; it is reachable on the account's own compose
  network only. The same is true of `db`, which additionally uses
  `MYSQL_RANDOM_ROOT_PASSWORD` — nothing in the stack connects as root.
* **Image attachments are decrypted into the document root.**
  `webui/controller/message/view.php:107-118` and
  `webui/model/message/attachment.php:57-80` write every `image/*` attachment of
  a previewed message to `/var/piler/www/tmp/i.<attachment_id>` — inside nginx's
  root, under a *sequential integer*, served with no authentication whatsoever,
  so `GET /tmp/i.1`, `i.2`, … enumerates them. `etc/cron.jobs` reaps them every
  five minutes (`find …/www/tmp -type f -name i.\* -exec rm -f {} \;`) and cron
  runs in the container, so the window is bounded rather than permanent.

  This is an upstream defect and it is **documented rather than fixed here**: the
  only mitigation available at the nginx layer is `deny all` on `/tmp/`, and the
  message viewer renders those files as `<img src="tmp/i.N">` from the browser,
  so denying them breaks inline image display in the product's main screen. An
  operator archiving sensitive mail should know about it. It is not reachable
  without somebody first previewing a message with an image in it.
* **Mailbox passwords for import jobs are stored in the clear.** The Import
  page writes `server`, `username` and `password` into the `import` table as
  plain columns (`webui/controller/import/list.php`, verified on a live
  instance), so a Piler instance archiving five mailboxes holds five reusable
  mailbox credentials in its own database. Nothing in the deployment makes that
  worse, and nothing here can make it better: it is how the feature is built.
  It is a reason to give Piler its own restricted mailbox accounts rather than
  the users' own.
* engine#181 does not apply: the docroot is inside the container, not the repo
  root, so nothing serves `~/project/docker-compose.override.yml`. Confirmed by
  probing `/.env`, `/.env.default`, `/.git/config`, `/docker-compose.yml`,
  `/docker-compose.override.yml`, `/panelalpha-setup.sh`, `/manticore.conf`,
  `/VERSION` and `/config.php.in` on the public domain: every one of them falls
  through `try_files` to the login page.
* `GET /js.php` answers 500 with an empty body — `webui/js.php` requires
  `view/javascript/piler-in.js`, which the installed layout does not have. It
  leaks nothing (the body is zero bytes) and nothing in the UI loads it; noted
  because it is the only unauthenticated endpoint that is not a 200.
* No stray compose file is moved aside in `prepare.sh`, so engine#166's
  `docker-compose.override.yml` trap is not stepped in. `docker/docker-compose.yaml`
  is one directory down and is not among `ComposeFileInspector::COMPOSE_FILE_CANDIDATES`,
  which are root-level names only.
* Datastore credentials are restated nowhere as bare `${VAR}` in an
  `environment:` block — they arrive only through `env_file`, which
  `ComposePlaceholders` does not read and `SidecarCredentials` cannot rewrite.

## What was verified

On a deployed account (`--memory-limit=2000`), over the account's own public
HTTPS domain, with no shell shortcuts in the parts that are the product:

1. **Sign-in.** `admin@local` with the password `hooks/prepare.sh` generated →
   `302 Location: …/index.php?route=health/health`, and the Health monitor page
   renders. The shipped `pilerrocks` no longer authenticates.
2. **Configuration through the UI.** An archived domain (`example.org`) and two
   users were created through the admin pages with ordinary form posts.
3. **Bulk ingest.** `pilerimport -e` archived an EML.
4. **IMAP pull, the documented path.** A mailbox job was created on the
   **Import** page; `util/import.sh` → `util/imapfetch.py` connected to a
   Dovecot server over IMAPS, pulled the message and reported
   `status=2 total=1 imported=1 error=0`.
5. **Search.** The owning user signed in over HTTPS and searched for a token
   that appears **only in the message body** — `wobbleflange9183` — and the
   message came back. Manticore's own `SELECT id FROM piler1 WHERE MATCH(…)`
   confirms all three messages are indexed.
6. **Read-back.** Opening the result rendered the decrypted body. `pilerget`
   on the stored message returns the original RFC822 bytes, which is the
   `piler.key` path end to end.
7. **Redeploy.** A second deploy re-cloned `~/project`, reused
   `~/.panelalpha/piler.env`, left all three archived messages and the changed
   admin password alone (`admin@local no longer carries the shipped password;
   left as it is`), and rewrote the `SITE_URL` line in place rather than
   appending a second one.

Four things worth knowing that came out of doing it:

* **`pilerimport` must be run from a writable directory.** With the image's
  default working directory it exits with `cannot write current directory!`.
  `docker compose exec --workdir /var/piler/tmp piler pilerimport …`.
* **`pilerimport -i` — the C IMAP client — did not store anything**, against
  either Dovecot 2.x or GreenMail. It connects, authenticates, lists folders,
  counts messages and FETCHes the body (the message is printed to stderr), then
  fails at `src/import_imap.c:364` with `Cannot find … in the message`:
  `download_email()` searches the *body* buffer for the *header* buffer's
  contents, and the comment above it expects `* 1 FETCH (UID 1 BODY[] {n}`,
  which neither server returns for a sequence-number FETCH. This is an upstream
  defect in 1.4.9 and it does **not** affect the path this recipe documents —
  the Import page and cron use `util/imapfetch.py`, which worked.
* **A self-signed mailbox server needs `verifyssl=0` in
  `/etc/piler/piler.conf`.** The default is 1, which is right; it is only worth
  knowing because the failure is `SSL peer certificate … was not OK` with no
  hint about where the setting lives.
* **The built-in administrator can search but cannot open messages.**
  `check_your_permission_by_id()` (`webui/model/search/search.php:691`) grants
  read access by email address or auditor role, and `admin@local` is the
  address of nothing. This is Piler's design — admins administer, auditors read
  — but a customer will hit it in the first five minutes. The route is to
  create a user whose addresses are the archived mailbox's, or an auditor.
  `~/.panelalpha/piler-credentials.txt` says so.

## What is not set up

**Outbound mail.** Daily reports (`util/daily-report.php`), automated searches
(`util/automated-search.php`) and "restore to mailbox" need an SMTP server the
engine does not provide. Set `SMTP_FROMADDR` and its companions in
`/etc/piler/config-site.php`.

**Retention.** Archives grow and do not shrink. `Policies → Retention` sets how
long messages are kept and `util/purge.sh` applies it nightly from cron. With no
policy configured, **nothing is ever deleted** — which is correct for a
compliance archive and is a disk-growth problem for the account.

**LDAP, Google/SSO login, two-factor and four-eyes** are all present in the
webui and all off by default. `ENABLE_LDAP_AUTH`, `ENABLE_IMAP_AUTH`,
`ENABLE_POP3_AUTH`, `ENABLE_GOOGLE_LOGIN` and `ENABLE_SSO_LOGIN` are 0, so the
local `user` table is the only authentication path — which is what makes
replacing its two seeded rows the whole of the credential story.

**TLS to the mailbox server** is Piler's own business and is configured per
import job; TLS for the web UI is terminated by the engine's proxy on the
account's certificate.

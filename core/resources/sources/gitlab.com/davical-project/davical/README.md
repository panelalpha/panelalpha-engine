# DAViCal

Upstream: <https://gitlab.com/davical-project/davical> · tracker:
panelalpha/playground/supported-apps#1249

A CalDAV and CardDAV server. Flat PHP with no Composer and no build step,
served from `htdocs/`, with PostgreSQL underneath doing a great deal of the
work — permission arithmetic, recurrence expansion and free/busy are stored
procedures, not PHP.

Verdict before this recipe: `serving-missing_entry`. Verdict with it:
`deploy-ok`, `serving: ok`, HTTP 200 — and, which matters more for a CalDAV
server, a working protocol (see **Verification** below).

## What the engine got right on its own

Detection is correct and this recipe does not change it. There is no
`composer.json`, so `PhpSourcesProbe` walks the tree, finds `htdocs/*.php` at
depth 1 and puts the checkout on `php-plain` — the `php` strategy at PHP 8.3,
the shared `php:{version}-apache-bookworm` base image, `~/project` bind-mounted
at `/app`, Apache on 8000. Every extension DAViCal checks for in
`htdocs/setup.php` — `pgsql`, `PDO`, `pdo_pgsql`, `gettext`, `iconv`, `xml`,
`curl` — is already in `PhpBaseImage::EXTENSIONS`, so nothing had to be added
to the image. `mod_rewrite` and `mod_headers` are both enabled
(`PhpApacheConfig::MODULES`), which is what makes the `.htaccess` this recipe
installs work at all.

## What it could not infer, and why

**The document root.** `PhpDocroot::CANDIDATES` is `['public', 'web',
'public_html', 'webroot']`, then the application root, then `src/` as a late
candidate for `index.php` only. DAViCal serves from `htdocs/`, which is on
none of those lists, and the repository root has no index file — so `detect()`
falls through everything, emits no `PA_DOCROOT`, and the base image serves
`/app`, which holds `README`, `INSTALL`, `Makefile`, `ChangeLog` and no index.
Apache answers 403 and the report says the entry point is missing. `docroot:
htdocs` is the fix and the only thing that can say it; being a plain relative
path it survives `PlatformManifest::readDocroot()`, where `.` would not
(engine #172).

**That there is a second repository.** This is the part a docroot line does
not cover, and on its own it is fatal. `htdocs/always.php` is the first line
of every entry point — `admin.php`, `caldav.php`, `index.php`, `public.php`,
`feed.php`, `freebusy.php`, `tools.php` — and its first real act is
`include_once('AWLUtilities.php')`. AWL (Andrew's Web Libraries) is a separate
project at <https://gitlab.com/davical-project/awl>, which upstream expects a
distribution package to have installed: `debian/control` pins it as
`libawl-php (>= 0.65-1~), libawl-php (<< 0.66)`. When the include fails,
always.php tries four hard-coded paths:

    ../../awl/inc          # relative to the CWD, which is the document root
    /usr/share/awl/inc     # Debian
    /usr/share/php/awl/inc # Fedora
    /usr/local/share/awl/inc

Three are absolute paths inside the image that the account uid cannot create.
The fourth, resolved from `/app/htdocs`, is `/awl/inc` at the root of the
container filesystem. None is reachable from a bind-mounted `~/project`, and
when the list runs out always.php prints `Could not find the AWL libraries` and
exits — for the admin pages and for every CalDAV request alike.

The engine clones one repository and there is no manifest key for a second, so
`hooks/prepare.sh` fetches the `r0.65` tag into `~/.panelalpha/awl-r0.65` once
and copies it to `~/project/awl` on every deploy (the checkout is wiped and
re-cloned each time — engine #173 — and a cached copy also means a redeploy
works when gitlab.com does not). `files/panelalpha/php/zz-davical.ini` puts
`/app/awl/inc` on the `include_path` so the *first* `include_once` succeeds
rather than falling through to paths that cannot exist here. The tag is pinned
rather than tracking the branch because `always.php` sets
`$c->want_awl_version = '0.65'` and `setup.php`'s dependency table compares it
against `awl_version()`.

**That there is no configuration file.** always.php looks in six places and
`include`s `davical_configuration_missing.php` and exits if it finds none. Five
are absolute paths under `/etc` or `/usr/local/etc`; the sixth,
`../config/config.php`, is the only one an account can write, and the
repository `.gitignore`s exactly that name and ships `config/example-config.php`
instead. `hooks/prepare.sh` writes it. It holds no secret — every value that is
one is a `getenv()` call, the same posture as the zentao recipe's
`config/my.php`.

**That the database is PostgreSQL and cannot be the account's own.**
`database: mysql` is the manifest key that provisions a database on the
account's own MySQL server — panel-visible, openable in phpMyAdmin, inside the
account's backup, costing no container — and `PlatformManifest::DATABASES` is
`['mysql']`, the only value the engine accepts. DAViCal has no MySQL port and
will not have one: `dba/caldav_functions.sql` is 47 KB of plpgsql,
`dba/rrule_functions.sql` 26 KB more, the application's own WHERE clauses call
`privilege_to_bits()` and `get_permissions()`, and `INSTALL` lists
"PostgreSQL: 8.2 or greater" as a prerequisite rather than an option.

So the recipe ships a `postgres:17-alpine` sidecar, and **the cost is real**:
a second container, and a volume the panel does not manage, does not show, and
does not back up. An account owner who deletes their MySQL backup and assumes
their calendars are in it is wrong. This is the same price the `tt-rss` recipe
pays for the same reason, and it is not avoidable while `DATABASES` is
`['mysql']`.

**That the installer cannot run here.** `dba/create-database.sh` shells out to
`createdb`, `createlang` and `psql` for every statement, creates two PostgreSQL
roles with `CREATE USER`, expects a superuser, and hands the rest to
`dba/update-davical-database`, a Perl program needing DBI, DBD::Pg and YAML.
The shared PHP base image has no `postgresql-client` at all and a Perl with
neither module — verified by running `php -m` and `which psql` against
`panelalpha/php:8.3-apache-bookworm`.

What that script *does*, once the shell is taken out, is a fixed list of SQL
files in a fixed order plus one UPDATE, and `files/panelalpha-davical-setup.php`
replays exactly that list through PDO — which the image does have. Nothing
reimplements the schema: every statement executed comes out of the repository's
own `.sql` files. `PDO::exec` on `pdo_pgsql` hands each file to the server's
parser whole, so the dollar-quoted plpgsql bodies survive where splitting on
`;` in PHP would cut them in half; no file in `dba/` uses a psql meta-command
or `COPY ... FROM stdin`, which is the one thing that would have made `psql`
genuinely necessary.

One thing is deliberately dropped: upstream's `davical_dba` / `davical_app`
privilege split. It needs a superuser to create the roles and exists because a
distribution's PostgreSQL is shared between applications. This sidecar has no
published port and exactly one client, so the role that owns the schema is the
role that connects, and there is nothing left to grant.

`dba/davical.sql` ends at revision 1.3.5 while `always.php` wants 1.3.6, so a
fresh install is one patch short out of the box; the setup script walks
`dba/patches/` generally rather than naming `1.3.6.sql`, because the upgrade
stage is the case that matters.

## Security

**The admin account ships with the password `nimda`.** `dba/base-data.sql`
seeds `usr` row 1 as `'admin', '**nimda'`, and `**` is AWL's marker for a
plaintext password (`session_validate_password`, `awl/inc/AWLUtilities.php:268`)
— so that is literally "admin" backwards, on an account with a public HTTPS
domain and a CalDAV endpoint that accepts HTTP Basic. `create-database.sh`
overwrites it with a `pwgen` value and prints it to a terminal nobody is
watching here. `panelalpha-davical-setup.php` replaces it on the install stage,
before Apache binds, with a password generated per account into
`~/.panelalpha/davical-app.env` (0600 in a 0700 directory), stored as AWL's
salted-SHA1 `*<salt>*{SSHA}<hash>` form rather than as plaintext — the
strongest of the three formats the application can verify. Verified in the
database after a deploy: `usr.password` for `admin` is `*TbRvA0aQ9*{SS…`, 57
characters, not `**nimda`. It is only rewritten while the seeded value is still
in place, so a redeploy leaves a password the owner has since changed alone
(verified: the second deploy logged *"built-in admin password is not
base-data.sql's seeded value; left untouched"*).

**`htdocs/setup.php` embeds the whole of `phpinfo()`** in its page
(`setup.php:497`). Its own gate is `$session->LoginRequired(null)` — *any*
authenticated principal, meaning every calendar user the account ever creates
— unless `$c->restrict_setup_to_admin` is set, which the generated config now
sets. But that call sits inside a `try` whose `catch` installs a
`setupFakeSession` returning `true` from `AllowedTo()` (`setup.php:215-222`),
and what lands in that catch is always.php failing — which is what a database
that has not finished starting looks like. The one state in which the page is
unauthenticated is the one in which something is already wrong, so it is denied
outright in `htdocs/.htaccess` as well. Verified 403 both anonymously and
while logged in as `admin`.

**The session cookie had no flags.** AWL writes it with the four-argument form
of `setcookie` (`awl/inc/Session.php:461`), which cannot express `httponly` or
`samesite`, and it is not a PHP session cookie, so `session.cookie_httponly`
does not reach it. `htdocs/.htaccess` rewrites it on the way out with
`Header edit Set-Cookie`. `Header always edit` does **not** work here — the two
operate on different header tables and PHP's `setcookie()` lands in
`headers_out`, not `err_headers_out`; measured, with `always` the cookie came
back unchanged and nothing was logged. `Secure` is deliberately not added: TLS
terminates at the engine's proxy, so an account also reachable over `http://`
would get a login form that never logs anyone in.

**Exposure, measured against a baseline 404 body rather than by status code.**
Everything outside `htdocs/` — `config/config.php`, `awl/inc/AwlQuery.php`,
`dba/patches/*.sql`, `inc/`, `scripts/`, `testing/`, `COPYING` — returns a 404
whose body is byte-identical to the 404 for a path that never existed, so
nothing is being rewritten to a front controller. `.git/`, `.env`,
`.env.default`, `docker-compose.yml` and `panelalpha-*` are 403 from the
generated vhost; `images/`, `js/` and `css/` are 403 rather than listings
(`Options -Indexes`). `metrics.php` answers unauthenticated but returns
`Metrics are not enabled.` and nothing else unless `$c->metrics_style` is set,
which the generated config leaves commented out. `upgrade.php` and `tools.php`
render the login form. `freebusy.php` and `feed.php` answer 401 without
credentials; `public.php` answers 403 *Anonymous users may only access public
calendars*.

**Two things left as upstream has them**, worth knowing. `$c->list_everyone`
defaults to `true`, so any authenticated principal can list the other
principals in the admin UI — reasonable for a shared calendar server, but it is
a default and not a decision this recipe made. And AWL's salted SHA-1 is not a
password hash by any modern standard; it is simply what
`session_validate_password` can verify, and the alternative in the same
function is plaintext.

**The database password is in `~/project/.env.default` at mode 644** (engine
#173). It has to be in `.env` for compose to interpolate it into the sidecar's
`environment:`, which is the only field that outranks what the sidecar miner
writes (engine #166, #189); `ProjectEnvironment::apply()` then copies it. Both
files are outside the document root here, so neither is web-readable, but this
is a known engine defect rather than something the recipe can close.

## Verification

Deployed on `mariusz.panelalpha.tools` at host load ~1.1–2.4, twice from
scratch on two separate accounts: `deploy-ok` in 60 s (preparing 8 s, cloning
4 s, running 40 s), `serving: ok`, HTTP 200, every baseline and `php` health
check passing. Runtime footprint: app 28 MiB of a 768 MiB cap, postgres 17 MiB
of 448 MiB, 178 MiB for the whole account.

A rendered admin page proves nothing about CalDAV, so the protocol was
exercised end to end over the public HTTPS domain:

* logged into the admin UI as `admin` with the generated password and created a
  principal `calendaruser` through the web form — DAViCal reported *"Creating
  new Principal record. Home calendar added. calendar / .out / .in. Home
  addressbook added. addresses"*;
* `PROPFIND` `Depth: 0` on `/caldav.php/calendaruser/calendar/` with that
  user's Basic credentials → **207 Multi-Status** with
  `<resourcetype><collection/><C:calendar/></resourcetype>`, the displayname,
  `<C:supported-calendar-component-set>` listing VEVENT/VTODO/VJOURNAL, and
  `<C:calendar-home-set>`;
* the same request with a wrong password → 401; with no credentials → 401;
  `calendaruser` PROPFINDing `/caldav.php/admin/` → 403;
* `OPTIONS` advertises `DAV: 1, 2, 3, access-control, calendar-access,
  calendar-schedule, extended-mkcol, bind, addressbook,
  calendar-auto-schedule, calendar-proxy`;
* `PUT` of a VEVENT with `RRULE:FREQ=WEEKLY;COUNT=3` → 201 with an ETag; `GET`
  returns it; a `REPORT` `calendar-query` with a `<C:time-range>` covering the
  second and third occurrences matches it — which only works if
  `caldav_functions.sql` and `rrule_functions.sql` really loaded, since the
  recurrence expansion is a stored procedure;
* `/.well-known/caldav` and `/.well-known/carddav` 301 to `/caldav.php/`
  through the rewrite in `htdocs/.htaccess`.

One caveat about *how* the principal was created, which is about the test
domain and not about DAViCal. The admin form is `enctype="multipart/form-data"`,
and a `*.panelalpha.online` name resolves to `eu1.withoutdns.com`, whose
openresty front end answers a genuine multipart POST with `302 ->
https://www.withoutdns.com/internal-server-error.html` — the request never
reaches the account. The same POST succeeds when pinned to the origin IP, and a
urlencoded POST of the same fields succeeds through the forwarder, which is how
the principal above was created. Nothing in the engine or this recipe is
involved; it is worth knowing because any app-support test of a file upload
through a withoutdns domain will fail this way.

## What a redeploy does to the data

Nothing. Principals, collections, every event and every vCard live in the
PostgreSQL volume, which survives; DAViCal writes no uploads to the filesystem
at all. The only per-deploy state in `~/project` is the vendored `awl/`, the
generated `config/config.php` and one line in `.env`, all three of which
`hooks/prepare.sh` recreates. Verified across two redeploys: the test event was
still readable over CalDAV afterwards and the admin password was untouched.
That is a better position than most PHP applications on this engine, and it is
a property of DAViCal's design rather than of this recipe. The account still
cannot see or back up that volume through the panel.

## Licence

GPL-2.0-or-later (`COPYING`). No additional condition on running it as a
network service, no badgeware clause, no ambiguity about the identifier.
Hosting for third parties is unambiguously permitted and the application is
shipped unmodified.

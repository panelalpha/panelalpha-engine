# manticoresoftware/manticoresearch

Manticore Search, deployed as an authenticated JSON search API on the account's
own domain. Tracker row `panelalpha/playground/supported-apps#297`.

## What a tenant gets, and what they do not

Manticore has **no admin web interface of its own**. There is no dashboard, no
login page and no settings screen to put on a domain, and nothing in this
recipe invents one. What the domain serves is Manticore's HTTP API on port
9308: `POST /cli` for SQL, `/insert`, `/bulk`, `/replace`, `/update`,
`/delete`, `/search` and the Elasticsearch-shaped endpoints its Buddy
`emulate-elastic` plugin answers, plus `POST /token` for a bearer token. A
tenant drives it from their own application, from a client library, or from
curl.

That makes this a real hosting product rather than a technicality, for one
reason: **Manticore is the thing a tenant's other site talks to.** A shop, a
docs site or a forum hosted elsewhere points its search at this domain over
HTTPS with a password. The same is true of every hosted Elasticsearch,
Typesense or Meilisearch service, none of which ship an admin UI either. What
it is *not* is something a customer opens in a browser and uses: opening the
domain gets a browser password prompt (the 401 carries
`WWW-Authenticate: Basic realm="Manticore daemon"`) and then a JSON banner.
That should be said plainly wherever this app is offered.

## Why authentication is the whole of the decision

Left at its defaults the `manticoresearch/manticore` image is **wide open**.
Measured on 29.9.0: an anonymous `POST /cli` with `create table t(title text)`
answers `Query OK`, an anonymous insert answers `"created": true`, and an
anonymous search reads the document back. A one-click deploy of the stock image
on a public domain would publish a writable search engine with no password on
it -- worse than not shipping the app.

The reason this recipe exists is that the posture is fixable **unattended**,
and fails closed while it is being fixed:

1. `searchd_auth=1` is in the account's env file before the container ever
   starts, so the image's `manticore.conf.sh` writes `auth = 1` into the
   generated config. `CheckAuth()` in `src/auth/auth_proto_http.cpp` runs for
   every request on every path -- there is no unauthenticated endpoint, not
   even `/` -- and with authentication enabled and **no users yet** the answer
   is `401 {"error":"Authorization header field missed"}`. So the window
   between "searchd is listening" and "the admin user exists" is closed, not
   open. Measured.
2. `searchd --auth-non-interactive` reads three lines on stdin -- login,
   password, password again (`ReadUserCredNonInteractive()` in
   `src/auth/auth_bootstrap.cpp`) -- creates the user with every permission,
   writes `auth.json` into `data_dir` and signals the running daemon to reload
   it. `hooks/prepare.sh` generates the password; `files/panelalpha/manticore/
   bootstrap.sh` feeds it in.
3. Two independent refusals then make "open" impossible to ship rather than
   merely unlikely, and both were exercised by turning `searchd_auth` off in a
   deployed account and rebuilding:
   * **`bootstrap.sh` stops serving.** Its last act, on every path, is an
     anonymous request to the daemon. Anything but 401 means the instance is
     open, and it kills searchd. The container exits, comes back, fails the
     same check and stops again. Measured: the domain answered 502 and no
     Manticore endpoint was reachable at all.
   * **`ready` fails the deploy.** It asserts both halves -- an anonymous
     request must answer 401 *and* the generated credential must answer 200 --
     and exits non-zero otherwise. The `verified` service turns that into a
     failed `docker compose up -d` and so a failed deploy. Measured: the
     rebuild endpoint answered 500 with `dependency failed to start`.

   The second is necessary because a report is not a refusal: an earlier
   version of this recipe had `ready` alone, and a rebuild with authentication
   off printed the refusal, exited 1, **and still reported success** --
   `compose up -d` starts containers without waiting for them.

Two things had to be got right for step 2 to work at all, both measured rather
than read:

* **The bootstrap must run as the daemon's own user.** Run as root it writes a
  root-owned `0600` auth.json, the reload fails with
  `AUTH RELOAD failed: ... auth.json unreadable: Permission denied`, and the
  daemon keeps refusing the credential it just wrote until the container is
  restarted -- with `searchd --auth-non-interactive` having exited 2 and the
  deploy none the wiser. `start.sh` does `gosu manticore` before the image's
  own entrypoint has dropped privileges.
* **It must run inside the daemon's container.** The bootstrap reads
  `pid_file`, sends SIGUSR1 to that pid and waits on a named FIFO
  (`src/daemon/daemon_ipc.cpp`), so a sibling container cannot do it. That is
  why the wrapper is an entrypoint rather than a service.

## Layout

```
panelalpha.yaml                          description only; the compose file is the recipe
hooks/prepare.sh                         generates the credential, writes the owner's note
overrides/docker-compose.yml             the stack
files/panelalpha/manticore/start.sh      entrypoint wrapper: backgrounds the bootstrap
files/panelalpha/manticore/bootstrap.sh  creates the admin user, once
files/panelalpha/manticore/ready.sh      refuses the deploy unless the instance is closed
```

Three services: `manticore` (the daemon), `ready` (the assertion) and
`verified` (an `exit 0` whose `service_completed_successfully` dependency on
`ready` is what makes `compose up -d` wait for it and fail on it).

## Decisions

**The published image, not the checkout.** The repository *is* the daemon: a
C++/CMake tree with vendored columnar, secondary, knn and embeddings libraries
and a clang toolchain to build them. Upstream publishes the result as
`manticoresearch/manticore`, pinned here to `29.9.0` (the release `latest`
pointed at) and overridable through `MANTICORE_IMAGE` in `~/project/.env`. The
checkout carries no usable version string of its own -- `src/sphinxversion.h.in`
reads `VERNUMBERS "0.0.0"` and the real one comes from `git describe` at build
time -- so there is nothing to pin a tag *from*, which is why the tag is
written out rather than derived.

**Why the verdict on #297 was `serving-missing_entry`, HTTP 403.** Nothing to
do with Manticore. With no recipe, detection read the repository root, found
the PHP that Manticore Buddy is written in, chose `strategy: php`,
`platform: php-plain`, `runtime: 8.3`, and served the C++ checkout over Apache.
There is no `index.php` at the root, so Apache answered 403, and
`checks/php/entry-served.yaml` reports a 403 on `/` as `missing_entry`. The
`inspect` endpoint still reports `strategy: php` with this recipe in place --
it answers about the repository, before an app config is applied.

**Only 9308 is published.** 9306 (MySQL protocol) and 9312 (binary) are in
neither `InternalPorts::KNOWN` nor `InternalPorts::NON_WEB`, so publishing them
would put two non-HTTP sockets in front of `DetectAppPort` with nothing to say
which is the site. They stay reachable on the account's own compose network,
behind the same authentication, where a tenant's own container can use them.
`AppPortAlignment` does not run against a recipe-supplied compose file at all
(it returns early unless the file to run is the generated one, and then only
looks at `services.app`), so the published port is whatever
`ComposePortScan` reads -- 9308, and measured as 9308 on a live deploy.

**No fake front page.** `/` answers 401 to an anonymous request, and that is
left alone. `checks/_baseline/no-server-error.yaml` is explicit that 4xx stays
healthy because "an API that answers 401 to an unauthenticated probe is
working", and a live deploy reports `healthy: true`, `serving: ok`,
`domain.verdict: ok` on exactly that 401. Adding a static index to turn the
probe green would be dressing, and it would have to be excluded from
authentication to work -- i.e. it would be the one unauthenticated thing on the
domain.

**The credential is outside `~/project`.** Every deploy empties the checkout
(engine#173), and `ProjectEnvironment::apply()` republishes `~/project/.env` as
a world-readable `.env.default`, so the password lives in
`~/.panelalpha/manticore/manticore.env` at 0600 inside a 0700 directory, reached
by a second `env_file:` entry. `~/project/.env` carries only the image tag.
`auth.json` -- the hash the daemon actually honours -- is in `data_dir`, i.e.
the `manticore_data` volume, which is also what makes the login survive a
rebuild.

**Measured deploy.** 45.2s, three times, on `mariusz.panelalpha.tools`:
`serving: ok`, `healthy: true`, `domain.verdict: ok`, published port 9308,
HTTP 401 anonymous and HTTP 200 authenticated over the public HTTPS name. A
control app deployed in the same batch answered `deploy-ok` / HTTP 200 in
90.3s. Memory: 34.5 MiB for the daemon container with a 1000-document table,
146 MiB for the whole account.

**`ready` is `alpine:3`.** A `ready` gate on a datastore image is resolved as a
sidecar and rewired into `app -> ready -> app`. It uses busybox `wget -S` and
matches the status line, because `wget`'s own exit code treats 401 -- the
answer this gate wants -- as a failure, and because adding curl would put an
apk mirror in the deploy path.

**Every `description:` is one line.** `CommandScript::descriptionLine()`
prepends a single `#` to a command's description when it writes the generated
script, so a second line becomes bare shell (engine#198). The manifest's
top-level `description` is folded (`>-`) with no blank lines, so the parsed
value contains no newline at all.

## Known upstream behaviour worth knowing

* **`POST /cli` swallows syntax errors.** A statement the daemon's grammar
  rejects is answered `Query OK, 0 rows affected` with HTTP 200 through `/cli`,
  while `/sql` returns the real `P01: syntax error ...` with 500. Measured on
  29.9.0 with `create user tester3 identified by '...'` (the daemon requires
  the username quoted). This makes `/cli` an unsafe place to run user
  management: a mistyped `SET PASSWORD` looks like it worked.
* **There is no `ALTER USER`.** The statements are `CREATE USER '<u>'
  IDENTIFIED BY '<p>'`, `DROP USER`, `SET PASSWORD '<p>' FOR '<u>'`, `GRANT`,
  `REVOKE`, `SHOW USERS`, `SHOW PERMISSIONS`, `TOKEN`. Quotes are required
  around every username and password.
* **`SET PASSWORD` does not rotate bearer tokens.** `TOKEN` does.

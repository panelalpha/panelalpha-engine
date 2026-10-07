# Simple-URL-Shortener (github.com/azlux/Simple-URL-Shortener)

A URL shortener in 12 PHP files and no dependencies: `index.php` looks a code
up and sends a 302, `shorten.php` writes a row, `login.php` and `list.php` are
the rest. MySQL or SQLite. No framework, no Composer manifest, no build step,
no vendor directory. Upstream's README opens by saying the project is finished
and that improvements should be forked.

Detection is right about both of the things it has to decide: `php-plain` on
the `php` strategy, because there is no `composer.json` anywhere, and the
document root is the repository root, which `PhpDocroot::detect()` reaches from
the root `index.php` (`PhpDocroot.php:80`), so `PA_DOCROOT=/app`. Without the
recipe every request answers **HTTP 200 with a stack trace in the body**.

## The error

    Warning: require(config.php): Failed to open stream: No such file or
      directory in /app/inc/bdd.php on line 2
    Fatal error: Uncaught Error: Failed opening required 'config.php'
      (include_path='.:/usr/local/lib/php') in /app/inc/bdd.php:2
    Stack trace:
    #0 /app/index.php(2): include()

`inc/bdd.php` line 2 is `require 'config.php'`. `.gitignore` line 2 is
`config.php`. Upstream's install instruction is "copy `inc/config.example.php`
to `inc/config.php`", so a clone never has one, and `bdd.php` reads it with no
guard — a `require`, so it is fatal rather than recoverable.

It answers **200** rather than 500 because the base image loads
no `php.ini` at all — `display_errors` goes to STDOUT — so the warning is
written into the response body, and writing a body sends the headers with the
200 that was already on them.

With `php_flag display_errors off` in a `.htaccess` and nothing else changed,
the same checkout answers **HTTP 500 with zero bytes**. So here the notice is
*publishing* a failure rather than being one. That is why
`php_flag display_errors off` is in the recipe *as well as* the config file:
without the file the deploy fails visibly instead of printing a stack trace to
visitors.

## What the engine could not infer

**The router.** Nothing in the repository routes `/abc12` to
`index.php?site=abc12`. Upstream's README gives an nginx block and a three-line
Apache rewrite as things the operator writes by hand, and ships neither, so a
clone serves a working form that mints short links which all 404.
`files/.htaccess` carries upstream's rule with the character class widened from
`[a-zA-Z0-9]+` to `[A-Za-z0-9_-]{1,30}`: the form's "Optional short url" field
is `maxlength=30` and accepts anything, so a custom code with a dash in it would
be created and then reachable nowhere.

**The configuration file**, above.

**The schema and the first account.** Upstream ships `installation.php`, an
unauthenticated page that creates both tables for whoever loads it and then
asks to be deleted by hand, and says "Create a user, the first one will be an
admin". `hooks/prepare.sh` deletes the file on every clone;
`files/panelalpha-setup.php` runs the DDL and creates the administrator from
the install stage, before Apache binds.

## Who can create links

**Signed-in users only, and the deploy creates exactly one account.** Upstream's
own "off" switch does not work.

`login.php:14` reads the constant as `if (ALLOW_SIGNIN)` — truthiness, not a
comparison — and every non-empty string is truthy in PHP, so
`config.example.php`'s documented `define('ALLOW_SIGNIN', 'false')` leaves
`POST login.php?signin` open to anyone who finds it. `header.php:35` *does*
compare against `'true'`, so the Sign Up button is hidden while the endpoint is
not; the only sign that it worked is the words `ACCOUNT CREATED.` in the
response body. And `login.php:19` grants `admin=1` to whoever registers while
the users table is empty, so an uninstalled instance on a public name is an
administrator account waiting for a stranger.

The recipe closes both without patching PHP:

* `ALLOW_SIGNIN` is defined as the **empty string** — falsy at `login.php:14`
  and still `!= 'true'` at `header.php:35`, so it is genuinely off in both
  places, and setting it to `'true'` still turns sign-up on correctly.
* `panelalpha-setup.php` creates the `admin` account from the deploy, so the
  first-user-wins slot is taken before the first request.

`PUBLIC_INSTANCE` is `'false'`. Turning it on makes the site an open URL
shortener for the whole internet under the customer's domain, which is what
phishing wants from a shortener; the operator README says so in those words.
A shortener is an open *redirect* by design — anyone holding a short link can
send anyone to its target — and that is the product, not a defect.

## Two upstream bugs, not fixed by the recipe

**Query strings are corrupted in the redirect.** `shorten.php:112` stores
`htmlspecialchars(strip_tags($_POST['url']))`, so a target like
`https://example.org/q?a=1&b=2` is written to the database as
`https://example.org/q?a=1&amp;b=2` — right for the `<a href>` `list.php`
renders it into, wrong for the `Location:` header `index.php:23` sends it in.

**An emoji is a blank 500.** `inc/bdd.php:7` pins the connection to
`charset=utf8` — utf8mb3 — so a four-byte character is invalid in the
connection's own charset before any column is reached:

    SQLSTATE[22007]: Incorrect string value: '\xF0\x9F\x8E\x89' for column
    `…_app`.`shortener`.`comment` at row 1 … in /app/shorten.php:46

uncaught, therefore an empty 500. No column charset can fix it.

Both are documented for the account owner in `README.panelalpha.md`.

## Authentication, and what is wrong with it

There *is* a login — a bcrypt password (`cost => 12`), a PHP session, Secure
and HttpOnly cookies set by the application itself in all four entry points.
Three things about it are worth an operator's attention, and one of them the
recipe has to compensate for:

* **Change Password never checks the old password.** `login.php:54` requires
  `old_password` to be non-empty and then never verifies it. There is no CSRF
  token on that form or on any other, so from a third-party page that is a
  one-request account takeover. `files/.htaccess` sets
  `session.cookie_samesite=Lax`, which is what stops it. PHP's session cookie
  carries no SameSite attribute without a `php.ini` and there is none,
  so this line is load-bearing rather than tidy.
* **The API token is a whole authentication.** `shorten.php:68` accepts
  `?token=` in a query string with no session at all, and `index.php:46` bakes
  the token into the bookmarklet's `javascript:` URL. Upstream fills the column
  with `uniqid()`, which is the current time in hex and therefore guessable to
  within a few thousand tries by anyone who knows roughly when the account was
  created; `panelalpha-setup.php` uses `bin2hex(random_bytes(7))`, the same
  15-character width and not a clock.
* **`list.php:6` reads `$_SESSION['admin']` with no `isset()`** for anyone who
  requests `list.php?UNKNOWN` while signed out. On this image that is a warning
  printed into the page; with the recipe it goes to the container's error log,
  which is where it belongs.

## Exposure

The document root is the repository root, so every file the deploy leaves
beside the application is a URL. **Status codes alone lie here**: the rewrite
sends any single-segment name of 30 characters or fewer to `index.php`, which
answers 302 for a code it does not know, so a code-only sweep sees a "live"
answer at `/config`, `/admin`, `/secrets` and everything else. Each of those is
0 bytes with `Location:` the site's own root. A name with a dot in it misses the
rewrite and 404s normally.

| path | blocked by |
| --- | --- |
| `/docker-compose.yml`, `/panelalpha-entrypoint.sh`, `/panelalpha-setup.php` | 403, vhost `^(?:docker-compose\.ya?ml\|panelalpha[-.])` |
| `/.env`, `/.htaccess`, `/.gitignore`, `/.git/config`, `/.git/HEAD` | 403, vhost `^\.(?!well-known)` |
| `/.panelalpha-admin-password` | 403, same rule — which is why the password is copied in under that name |
| `/docker-compose.override.yml` | 403 — **by this recipe, see below** |
| `/inc/config.php`, `/inc/config.example.php`, `/inc/bdd.php`, `/inc/header.php`, `/inc/` | 403, `files/inc/.htaccess` |
| `/installation.php` | 404, deleted by the hook on every clone |
| `/database.sqlite3`, `/db.sqlite` | 403 by name, though no such file exists on a MySQL deploy |
| `/assets/`, `/assets/css/` | 403, vhost `Options -Indexes` |

**A gap in the vhost's deny rule applies to this application.** The vhost's rule is anchored on
`docker-compose\.ya?ml` and does not match the `.override.` spelling, and here
the document root *is* the repository root, so without the recipe's own deny a
`docker-compose.override.yml` would be served with its contents. This recipe
ships no `overrides/` directory, so there is no such file on a normal deploy —
the two-line block means one added later, by another recipe or by the operator,
cannot be published by accident.

**No credential is written into the document root.** `inc/config.php` reads
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` from the
environment rather than spelling them out, so even if PHP ever stopped being
the handler for that path there would be nothing in the file to publish. It is
0600 and behind `files/inc/.htaccess` as well.

## Data, and what a redeploy does to it

In the **account's own MySQL database** — the one in the panel and in
phpMyAdmin — which is what `database: mysql` provisions. Not the SQLite the
application also supports, and not only for the usual reason:

* Upstream's `SQLITE3_FILE` default is `./database.sqlite3`, **inside the
  document root**. Upstream's own nginx snippet denies `\.(sqlite3|ht)$` and
  its Apache snippet does not, so the Apache installation upstream documents
  publishes every short link, every target URL and every bcrypt hash as a
  downloadable file.
* It is also inside `~/project`, which a redeploy clears and re-clones.
  Every short link an account had handed out would stop working
  on the next deploy **while the people holding those links still held them** —
  the failure mode a shortener can least afford.

MySQL costs nothing extra here: it is the account's own server, already running
for every account on the host, so this is not a database container.
`files/.htaccess` denies `*.sqlite3` regardless, for the operator who switches.

The schema is `installation.php`'s with three corrections — DDL is not code, so
a better version of it is not a patch anyone maintains:

* `short` is `VARCHAR(30)`, not `char(URL_SIZE)`. `URL_SIZE` is 5, the length
  of a *generated* code, but the form offers a 30-character custom one. On
  `char(5)` under `STRICT_TRANS_TABLES` every custom code longer than five
  characters is a `Data too long` error, and `CHAR` right-pads the shorter ones
  so `index.php:12`'s lookup never matches them again.
* `users.username` is the primary key. Upstream's users table has no key of any
  kind, so two accounts can share a name and `login.php:34` takes whichever row
  MySQL returns first.
* `IF NOT EXISTS`, because the command replays on the upgrade stage.

A redeploy keeps the short links, the admin password and the link list, and
`installation.php` stays deleted. What a redeploy *does* reset, and the
operator README says so: `inc/config.php` is rewritten, so edits to it are lost;
and PHP's session files are in the container, so everyone signed in is signed
out.

## Readiness

No readiness gate is added. `database: mysql` makes the generated entrypoint run
a `wait-for-mysql` step (60 tries at 2s) immediately before the setup command,
and the setup is two `CREATE TABLE`s and one `INSERT`. `panelalpha-setup.php`
keeps a five-try connect loop for the database-and-user case that gate does not
cover.

The probe sending `Host: 127.0.0.1` is harmless here: `DEFAULT_URL`
resolves from the `APP_URL` environment variable first and only falls back to
`HTTP_HOST`, so a probe request cannot make the site advertise short links on
`127.0.0.1`.

## Known limits

* **The stored XSS in the GET/bookmarklet path is not patched.**
  `shorten.php:59-66` stores `comment` and `url` raw where the POST path
  escapes them, and `list.php:46`/`:48` echo them unescaped. With
  `PUBLIC_INSTANCE` off it is self-inflicted — you need a valid token, which
  means you already have an account — and the recipe's defaults are what keep
  it there. The moment `PUBLIC_INSTANCE` is `'true'` it becomes cross-user
  against the administrator's `list.php?UNKNOWN` view, which is said in the
  operator README next to the switch.
* `list.php:63` is `if ($username = 'UNKNOWN')` — an assignment, so the
  bulk-delete form always posts as `UNKNOWN`. Fixing it means rewriting a page,
  and upstream says to fork.
* `login.php:59` echoes before calling `header()` on the change-password path,
  so that request logs two `Cannot modify header information` warnings. Cosmetic
  now that they go to the log rather than the page.
* No second account is created. Adding people means inserting a row with a
  bcrypt hash by hand; the operator README spells out the three columns that
  matter and why the token is a credential.
* `WEB_THEME` is `'dark'`, upstream's example value. There is no way to ask the
  customer at deploy time, and both themes ship.

#!/bin/bash
# Account shell, after the clone and before the build. The recipe's files/ --
# .htaccess, inc/.htaccess and panelalpha-setup.php -- are already beside the
# application by the time this runs.
#
# Four things, all of which have to be settled before Apache binds:
#
#   1. inc/config.php, which does not exist in a clone and whose absence is the
#      entire `serving-php_error` verdict.
#   2. The admin password, which has to outlive the checkout.
#   3. installation.php, an unauthenticated schema installer upstream tells you
#      to delete by hand.
#   4. One redirect that corrupts every short link whose target has a query
#      string.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/simple-url-shortener"

if [ ! -f index.php ] || [ ! -f inc/bdd.php ]; then
    echo "[shortener] no index.php or inc/bdd.php -- this is not a Simple-URL-Shortener checkout" >&2
    exit 1
fi

# ~ itself is root-owned 0755, so nothing can be created directly in it;
# ~/.panelalpha is created with the account and belongs to it.
mkdir -p "${DATA_HOME}"
chmod 700 "${DATA_HOME}"

# ----------------------------------------------------------- the admin password
#
# Generated once and never regenerated. Every deploy re-clones over ~/project
# (engine#173) while the account's MySQL database -- and the bcrypt hash in it
# -- stays exactly where it was, so a password generated beside the code would
# be a new password on every redeploy, matching nothing.
#
# The umask is inside a subshell: it has to cover the redirection that creates
# the file and must not leak into the rest of this script. tr drops the three
# base64 characters that are painful to retype.
PW_STORE="${DATA_HOME}/admin-password"
if [ ! -f "${PW_STORE}" ]; then
    ( umask 077; openssl rand -base64 18 | tr -d '/+=' > "${PW_STORE}" )
fi
chmod 600 "${PW_STORE}"

# The container cannot see ~/.panelalpha -- only ~/project is bind-mounted at
# /app -- so the value is copied in as a dotfile. The generated vhost denies
# every path component starting with a dot except .well-known:
#     <FilesMatch "^\.(?!well-known)"> Require all denied </FilesMatch>
# (resources/deploy/templates/apache-vhost.stub), so this is not web-readable
# even though the document root is the project root.
( umask 077; cp "${PW_STORE}" .panelalpha-admin-password )
chmod 600 .panelalpha-admin-password

# --------------------------------------------------------------- inc/config.php
#
# THE fix. inc/bdd.php line 2 is `require 'config.php'`, .gitignore line 2 is
# `config.php`, and upstream's install instruction is "copy inc/config.example.php
# to inc/config.php" -- so a clone never has one and every request answers:
#
#     Warning: require(config.php): Failed to open stream: No such file or
#       directory in /app/inc/bdd.php on line 2
#     Fatal error: Uncaught Error: Failed opening required 'config.php'
#
# under HTTP 200, because display_errors is on (engine#185) and printing the
# warning sends the headers before the fatal is reached.
#
# Written on every deploy rather than only when missing: ~/project is re-cloned
# each time, so there is never a file here to preserve. Everything an operator
# would want to change is either read from the environment or listed at the
# bottom under one heading.
cat > inc/config.php <<'PHPEOF'
<?php
/**
 * Written by PanelAlpha. Rewritten on every deploy -- ~/project is cleared and
 * re-cloned each time (engine#173), so edits here do not survive one. The
 * settings under "Yours to change" are the ones worth changing; see
 * ~/.panelalpha/simple-url-shortener/README.panelalpha.md.
 *
 * inc/bdd.php does `require 'config.php'` and then builds its PDO DSN out of
 * these constants, so this file is the whole of the application's configuration.
 */

// ------------------------------------------------------------------ database
//
// The MySQL database and user the engine provisioned on the account's own
// server -- visible in the panel, manageable in phpMyAdmin, and untouched by a
// redeploy. Read from the environment rather than written out, so no credential
// is ever on disk inside the document root.
define('DATABASE_TYPE', 'mysql');

$pa_db_host = getenv('DB_HOST') ?: '127.0.0.1';
$pa_db_port = getenv('DB_PORT') ?: '3306';
// bdd.php:7 builds the DSN by concatenation -- 'mysql:host=' . MYSQL_HOST .
// ';dbname=' . ... -- and there is no MYSQL_PORT constant anywhere in the tree,
// so a non-default port has to travel inside this one as a second DSN
// parameter. Unlovely; it is the only place it can go without editing bdd.php.
define('MYSQL_HOST', $pa_db_port === '3306' ? $pa_db_host : $pa_db_host . ';port=' . $pa_db_port);
define('MYSQL_DATABASE', getenv('DB_DATABASE') ?: '');
define('MYSQL_USER', getenv('DB_USERNAME') ?: '');
define('MYSQL_PASSWORD', getenv('DB_PASSWORD') ?: '');

// Only read when DATABASE_TYPE is 'sqlite3', which it is not. Deliberately an
// absolute path under a mount point that does not exist: upstream's default is
// './database.sqlite3', which lands inside the document root -- one request
// away from publishing every link, every target and every password hash -- and
// inside ~/project, which the next deploy deletes. Switching to SQLite means
// adding a bind mount first; see the README named above.
define('SQLITE3_FILE', '/data/shortener.sqlite3');

// ---------------------------------------------------------------- public URL
//
// Every short link this application hands out is DEFAULT_URL . '/' . code
// (shorten.php:125), and index.php:25 sends an unknown code here, so a wrong
// value here is a shortener that produces broken links. The engine sets APP_URL
// on the container to the account's own https:// name; resolved at runtime
// rather than baked in, so a domain change is right on the next request instead
// of on the next deploy. No trailing slash -- upstream's example says so, and
// the concatenation above is why.
$pa_url = getenv('APP_URL');
if (!is_string($pa_url) || preg_match('#^https?://#i', $pa_url) !== 1) {
    $pa_url = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? 'https://' . $_SERVER['HTTP_HOST']
        : 'https://localhost';
}
define('DEFAULT_URL', rtrim($pa_url, '/'));

// ---------------------------------------------------------- Yours to change
//
// URL_SIZE   how many characters a generated code has. 5 is 62^5 = 916 million;
//            shorten.php:29 loops until it finds a free one, so raising it is
//            safe and lowering it on a busy instance is not.
define('URL_SIZE', 5);

// WEB_THEME  'dark' or 'light'. Names assets/css/spectre-<theme>.css.
define('WEB_THEME', 'dark');

// PUBLIC_INSTANCE  'true' lets anyone who can reach this site create short
//            links without logging in. Read this before you set it: it makes
//            the site an open redirect service for the whole internet, which is
//            what phishing wants from a shortener, and it is the account's
//            domain and the account's reputation that carry the result. It also
//            turns the unescaped `comment` in the GET/bookmarklet path
//            (shorten.php:65) into stored XSS that fires in the admin's browser
//            on list.php?UNKNOWN. Any other value, including the empty string,
//            means off -- every test of it is `== 'true'`.
define('PUBLIC_INSTANCE', 'false');

// ALLOW_SIGNIN  'true' lets visitors register their own accounts.
//
//            The empty string, not 'false', and that is not a style choice.
//            login.php:14 reads this constant as `if (ALLOW_SIGNIN)` --
//            truthiness, not a comparison -- and every non-empty string in PHP
//            is truthy, so upstream's own documented "off" value of 'false'
//            leaves POST login.php?signin open to anyone who finds it. The
//            button is hidden (header.php:35 does compare against 'true') and
//            the endpoint is not. '' is falsy there and also != 'true' here, so
//            it is genuinely off in both places; set it to the string 'true' to
//            turn sign-up on, and nothing else.
define('ALLOW_SIGNIN', '');
PHPEOF
chmod 600 inc/config.php

# ------------------------------------------------------------ installation.php
#
# Upstream's installation procedure is: load installation.php in a browser, then
# delete it. It has no authentication and no lock file -- whoever loads it first
# owns the schema. On a public name that is the first-visitor-wins hole found in
# CouchCMS, Chyrp Lite, NocoDB and Mattermost. panelalpha-setup.php runs the DDL
# from the install stage instead, so this file has nothing left to do; it comes
# back with every clone, which is why the removal is here and not a one-off.
rm -f installation.php

# ------------------------------------------- two upstream bugs, deliberately unfixed
#
# Recorded rather than patched: a recipe configures and secures an application,
# it does not repair it. Both are upstream's to fix and are documented for the
# account owner in README.panelalpha.md.
#
# 1. Query strings are corrupted in the redirect. shorten.php:112 stores
#    htmlspecialchars(strip_tags($_POST['url'])), so https://example.org/?a=1&b=2
#    is written as ...?a=1&amp;b=2. That is right for the <a href> list.php
#    renders and wrong for the Location: header index.php:23 sends, where the
#    target receives a parameter literally named `amp;b`. Every shortened URL
#    carrying a query string redirects to the wrong place, silently.
#
# 2. The connection charset is utf8mb3. inc/bdd.php:7 pins charset=utf8, so a
#    comment or URL outside the Basic Multilingual Plane is invalid in the
#    connection's own charset before the column is reached -- SQLSTATE[22007],
#    thrown out of shorten.php:46 as an uncaught PDOException, i.e. a 500 with an
#    empty body. Measured: ASCII and Latin-2 accented comments store fine, one
#    emoji is a 500.

# ------------------------------------------------------------------- operator
if [ ! -f "${DATA_HOME}/README.panelalpha.md" ]; then
    cat > "${DATA_HOME}/README.panelalpha.md" <<'MDEOF'

## Two upstream bugs this recipe does NOT fix

Both are defects in the application itself. A PanelAlpha recipe configures and
secures an app; repairing it is upstream's job. Report them at
https://github.com/azlux/simple-url-shortener/issues

**1. A shortened URL containing a query string redirects to the wrong place.**
`shorten.php:112` stores `htmlspecialchars(strip_tags($_POST['url']))`, so
`https://example.org/?a=1&b=2` is saved as `...?a=1&amp;b=2`. That is correct for
the link `list.php` renders, and wrong for the `Location:` header `index.php:23`
sends -- the target receives a parameter literally named `amp;b`. It fails
silently. Until upstream fixes it, avoid shortening URLs with query strings, or
apply `html_entity_decode()` at `index.php:23` yourself.

**2. Emoji and other non-BMP characters cause a 500.** `inc/bdd.php:7` pins the
MySQL connection to `charset=utf8` -- the three-byte `utf8mb3`. A comment or URL
outside the Basic Multilingual Plane is rejected by the connection's own charset
before the column is reached (`SQLSTATE[22007]`), surfacing as an uncaught
PDOException from `shorten.php:46`: a 500 with an empty body. ASCII and accented
Latin text are unaffected. The column charset cannot fix it; the connection is
where the bytes are rejected.
<!-- Written by PanelAlpha. -->

# Simple-URL-Shortener

## Signing in

    username  admin
    password  in the file `admin-password` beside this one

That account was created by the deploy and it is an administrator. Upstream's
rule is "the first user created will be an admin", so an installation with an
empty users table hands administrator rights to whoever registers first; the
deploy takes the slot so that nobody else can.

Change the password from the site: **Connected as admin -> Change Password**.
The file beside this one is not read again after the first deploy and is not
updated when you change the password, so once you have changed it, delete it.

## Who can create short links

Only signed-in users, and only you have an account. There is no way to register
one from the site, and no way to create one except from here.

To add a second person, insert a row in the `users` table of the account's
database (phpMyAdmin, in the panel):

  * `password` must be a PHP `password_hash($pw, PASSWORD_BCRYPT, ['cost'=>12])`
  * `token` is 15 characters or fewer and is a credential in its own right --
    one GET to `shorten.php?url=...&token=...` creates a link with no session.
    Use random hex, not a word.
  * `admin` = 1 lets them see links created anonymously; 0 is the normal case.

Turning on self-registration is `ALLOW_SIGNIN` in `~/project/inc/config.php`,
and the value that turns it on is the string `'true'`. Note that this file is
rewritten on every deploy.

## Where the data is

In the account's own MySQL database -- the one in the panel and in phpMyAdmin.
Table `shortener` holds the links, `users` holds the accounts. A redeploy
clears and re-clones `~/project` and does not touch the database, so short
links survive one. Nothing you need is in the checkout.

What a redeploy does reset: `inc/config.php` (rewritten from this recipe, so
edits to it are lost -- and edits to `URL_SIZE` matter, because links made at
one length stay at that length), and PHP's session files, so everyone signed in
is signed out.

## SQLite

This application supports it, and this deploy does not use it. Upstream's
default puts the database file at `./database.sqlite3` -- inside the document
root, where it is one request away from publishing every link, every target URL
and every password hash, and inside `~/project`, which the next deploy deletes.
MySQL is provisioned, backed up with the account, and visible in the panel.

If you want SQLite anyway: add a bind mount for a directory under
`~/.panelalpha/` in a `docker-compose.override.yml`, point `SQLITE3_FILE` at an
absolute path inside it, and set `DATABASE_TYPE` to `'sqlite3'`. Both of those
constants are rewritten on every deploy, so this is not a supported shape.

## Things upstream does that are worth knowing

  * A shortener is an open redirect by design. Anyone with one of your short
    links can send anyone to the target. That is the product.
  * `PUBLIC_INSTANCE` in `inc/config.php` lets *anyone* create links without an
    account. On a public name that makes this a free redirect service for
    phishing, under your domain. Leave it off.
  * The bookmarklet ("Bookmark" on the home page) puts your API token in a
    javascript: URL in your bookmark bar. It is a password. Treat it like one.
  * Deleting a link only deletes your own: `list.php` filters on the signed-in
    username.
  * **Change Password does not check your old password.** `login.php:54` asks
    for it and never verifies it -- anything non-empty is accepted. There is no
    CSRF token on that form either, so a page you visit in the same browser
    could have taken your account over with one POST. PanelAlpha sets
    `session.cookie_samesite=Lax` in `~/project/.htaccess`, which is what stops
    that; if you replace that file, keep the block.
  * A comment or a URL containing an emoji used to be a blank 500 --
    `inc/bdd.php` opened the connection as three-byte utf8. PanelAlpha changes
    that one token to `utf8mb4` on every deploy.
MDEOF
fi
chmod 600 "${DATA_HOME}/README.panelalpha.md"

echo "[shortener] prepared: admin password at ${PW_STORE}"

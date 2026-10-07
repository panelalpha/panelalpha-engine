# ClipBucket V5

A self-hosted video-sharing platform — channels, collections, playlists,
photos, an admin area and an ffmpeg transcoding pipeline — in flat PHP with
MySQL underneath and no Composer at the repository root.

Upstream: <https://github.com/MacWarrior/clipbucket-v5> (5.5.3, revision 188,
`86d81659`).

## What the engine could not infer

**The document root is `upload/`.** `PhpDocroot::CANDIDATES` is
`['public', 'web', 'public_html', 'webroot']`
(`core/app/Lib/Deploy/Platform/Runtime/Php/PhpDocroot.php:25`), then the
repository root, then `src/` as a late candidate (`PhpDocroot.php:45`).
ClipBucket serves from `upload/`, which is on none of those lists, and the
repository root holds `README.md`, `LICENSE`, `dockerfile`, `package.json`,
`docker/` and `utils/` and no index file — so `detect()` falls through
everything and returns `''`, no `PA_DOCROOT` is emitted, and
`panelalpha-serve.sh` falls back to `/app` because there is no `/app/public`.
Apache answers 403 on a directory with no index.

`docroot: upload` is the whole fix, and it is enough on its own:
`upload` is a plain relative path, so unlike `.` it survives
`PlatformManifest::readDocroot()` (`PlatformManifest.php:297-318`), which folds
both `''` and `'.'` to "undeclared". **That fold does not bite here.** This is
exactly the case the manifest key exists for — `_schema.json` names `upload` in
its own example, for OpenCart. Adding `upload` to the probe list would be the
wrong fix: `upload/` is a directory name a great many applications use for
*uploaded content*, which is the last thing to point a document root at.

**Serving `upload/` is also what keeps the deploy's own files off the web.**
The repository root is the *parent* of the document root, so `.git/`,
`.env.default`, the generated `docker-compose.yml`, `docker-compose.override.yml`
and the `panelalpha-*` scripts are unreachable by construction rather than by
an `.htaccess` rule.

**The database.** MySQL or nothing — `cb_install/sql/structure.sql` is MySQL
DDL, every data file beside it is a MySQL dump, and `Clipbucket_db` speaks
mysqli directly with no other driver. The checkout ships no `.env` and no
compose file at its root to point itself at a server. `database: mysql` gets
one on the account's own MySQL server.

**The installation, ffmpeg, the php.ini and where the media lives** are the
rest of this recipe and have sections of their own below.

## The ffmpeg answer

**The shared PHP base image has no ffmpeg, no ffprobe and no mediainfo.**

**For ClipBucket that is not a degraded feature, it is the product.** Two
separate facts:

- Upstream's own installer treats FFmpeg, FFprobe and MediaInfo as *required
  software* (`cb_install/functions_install.php:95-104`) and makes only MySQL
  Client and Git skippable (`:127-132`). A human driving the wizard cannot get
  past the precheck without all three: `$everything_good` stays false and the
  "Continue" button is disabled.
- At runtime an upload becomes a `cb_video` row in status `Processing`, a file
  in `files/temp/`, and a queue row. What turns that into a playable video is a
  background `php actions/video_convert.php <file_name>`, whose entire body is
  `FFMpeg::ClipBucket()` (`includes/classes/ffmpeg.class.php:1345-1369`). So
  **without ffmpeg an upload is accepted and then sits in the queue as a file
  nobody can play** — not an error, not a rejection, just a video that never
  appears. That is the worst of the three possible answers.

So `panelalpha.yaml` asks for them with `system_packages: [ffmpeg, mediainfo]`:
the engine builds a variant of the shared PHP base image with Debian's
packages, once per host, and only accounts deploying an app that asks for the
set load it. The first deploy of a new set waits for that build and fails
rather than deploying without the packages. The install then writes
`/usr/bin/ffmpeg`, `/usr/bin/ffprobe` and `/usr/bin/mediainfo` into the
`ffmpegpath`, `ffprobe_path` and `media_info` config rows, which
`System::get_binaries()` reads *before* it ever falls back to `which`
(`includes/classes/system.class.php:547-620`), and the setup stage refuses to
continue if any of the three is missing.

Earlier versions of this recipe downloaded static builds (johnvansickle's
ffmpeg, MediaArea's MediaInfo CLI, ~180 MB on disk) into
`~/.panelalpha/clipbucket/bin` on every account. The config rows are written on
every deploy, so an account installed that way is re-pointed at `/usr/bin` by
its next redeploy; the old `bin/` directory is left alone and can be deleted.

### Nothing needs a cron, and that is not obvious

ClipBucket's conversion queue is drained by an admin *tool*,
`AdminTool::launchVideoConversion` (`includes/classes/admin_tool.class.php:1810`),
whose registered frequency is `* * * * *` — and `admin_area/setting_advanced.php:334`
offers you a crontab line for it. A hosting account has no cron, and the first
reading of this is that uploads queue forever.

They do not. `automate_launch_mode` defaults to `user_activity`
(`cb_install/sql/configs.sql`), and `includes/common.php:319-331` then runs on
**every non-CLI request**: if the `automate` tool has not started in the last
minute it backgrounds it with `AdminTool::launchCli()`, and that tool launches
every tool that is due, including the conversion one. ClipBucket drives its own
queue off its own traffic.

Two consequences worth knowing. A site with no visitors converts nothing — but
this deployment's own compose healthcheck polls `/` every five seconds, so in
practice the queue is drained within a minute either way. And
`fastcgi_finish_request()` does not exist under mod_php, so ClipBucket's SSE
progress bar on the upload page is disabled and the admin area says so; the
conversion itself is unaffected, because it is `exec(... &)` and not SSE.

## Security

### The web installer ships unlocked, and behind it is remote code execution

`upload/cb_install/` is gated on one thing: whether
`upload/files/temp/install.me` exists
(`cb_install/functions_install.php:2-11`, `cb_install/ajax.php:11`).

**That file is committed to the repository.** `upload/files/temp/.gitignore` is

```
/*
# But not these files...
!.gitignore
!install.me
```

— the directory is ignored and the lock file is whitelisted by name. So every
clone of this repository arrives with the installer open, and a deploy that
does nothing about it publishes it on a public HTTPS address.

What is behind it is not a wizard you have to be quick about:

- **`ajax.php` step `create_files` writes `upload/includes/config.php`** — the
  file the application includes on every request — by substituting
  `$_POST['dbhost']`, `['dbname']`, `['dbuser']`, `['dbpass']`, `['dbport']`
  and `['dbprefix']` into `cb_install/config.php`'s single-quoted PHP strings
  with **no escaping of any kind** (`ajax.php:183-195`). A posted password
  containing `' . <expression> . '` is arbitrary PHP in that file.
  Unauthenticated remote code execution, from the network, with no session.
- **`ajax.php` opens a MySQL connection to any host and port it is posted** and
  reports the outcome (`ajax.php:29-56`) — a connect probe for anything the
  container can reach.
- **The wizard's `adminsettings`/`finish` path sets the administrator's
  username, password and email from POST** (`modes/finish.php:2-10`), and the
  password field's default value in the form is the literal string `admin`
  (`modes/adminsettings.php:31`).

`hooks/prepare.sh` deletes `install.me` on the account, after the clone and
before anything is built, so there is no window at all — not "a short one".
`install.me.not` is deliberately **not** created as a substitute: read the gate
carefully and with `install.me` absent but `install.me.not` present, the
installer skips both the redirect and the `lock` screen and is fully open
again. The only safe state is neither file, and the hook removes all three
names it knows about (`install.me`, `install.me.not`, `development.dev`), on
both sides of the media mount.

`files/upload/cb_install/.htaccess` is the second lock. One deleted file
between a public URL and unauthenticated RCE is not a margin worth keeping, and
nothing in a running ClipBucket fetches a URL under `cb_install/` — the
directory's other use is the SQL that `AdminTool` and `Update` read off the
filesystem. Denying it also stops `cb_install/sql/*.sql` being served as plain
text, which is the schema and the whole English translation table.

**So: can a visitor claim the first admin? No.** The installer is gone before
Apache binds and denied even if it came back, and the administrator is created
by `files/panelalpha-install.php` with the login the engine generates
(`credentials:` in `panelalpha.yaml`, returned by
`GET /projects/{name}/app-credentials`, MCP `app_credentials_get`; an account
deployed before this keeps the password from
`~/.panelalpha/clipbucket/admin-credentials` through `adopt_from`). `add_admin.sql` seeds `userid = 1` with an
*empty* password and the seed phase fills it in, which is also why the install
check requires `password <> ''` — a half-finished install must not look
finished.

### What is reachable in the document root without a session

**Status codes lie here.** Upstream's `upload/.htaccess` sets
`ErrorDocument 404 /404` and `ErrorDocument 403 /403`, which render
ClipBucket's own pages, and 302-*redirects* several families of path to `/403`
rather than denying them. A status code alone says very little; compare the
body against ClipBucket's 404 and 403 pages.

Reachable without this recipe, and closed by it:

| Path | What it is |
| --- | --- |
| `/cb_install/*` | the installer, above |
| `/vendor/composer/installed.json` | the exact installed version of every dependency, which is a CVE shopping list |
| `/vendor/**/*.php` | library files Apache would *execute* out of context (`vendor/filp/whoops/src/Whoops/Run.php`) |
| `/vendor/smarty/smarty/composer.json` etc. | package metadata throughout the tree |
| `/files/logs/<date>/<file_name>.log` | the per-video conversion log: absolute container paths, every source and output stream's codec and bitrate. `file_name` is in the page HTML of every video, so these are *enumerable*, not merely reachable |
| `/composer.json`, `/package.json` | the dependency set and the exact version |

`vendor/` cannot simply be denied: ClipBucket installs three frontend libraries
with Composer and links them straight out of it on every page and in the admin
area — `components/jquery`, `select2/select2`, `fortawesome/font-awesome` and
its fonts. So `files/upload/vendor/.htaccess` is an **allow-list**: a request
under `vendor/` is served only if it ends in an asset extension, and everything
else is 403. A deny-list would have to guess at every extension a Composer
package might ship and would be wrong on the next dependency.

What upstream already gets right, and it is a decent amount:

- `includes/`, `changelog/` and `files/temp/` are 302'd to `/403` — so
  `includes/config.php`, which holds the database configuration, is not
  reachable even though it is inside the document root.
- `admin_area/` and every page under it redirect to the login for an
  anonymous request.
- **A `.php` file under `files/` is bounced before Apache can run it**: it is
  redirected to `/403`, and a `.php.jpg` is served as plain text, not
  executed. `files/.htaccess`'s `AddHandler cgi-script` + `Options -ExecCGI` is
  the belt, the parent's `RewriteRule ^(.*/)?files/.*\.php` is the braces.
- Directory listings are off everywhere (`Options -Indexes` in the vhost).

### `register_argc_argv`, inert here

ClipBucket ships CLI-only entry points *inside the document root* with no
`php_sapi_name()` guard: `actions/video_convert.php` and
`actions/verify_converted_videos.php` both begin `$argv[1] ?? false`. With
`register_argc_argv` on — and the base image, having no php.ini at all, leaves
it at the compiled-in `On` — a query string containing no `=` is split on `+`
into argv. `GET /actions/video_convert.php?x+<file_name>` looks like an
unauthenticated way into the conversion driver with a `file_name` that is
public in every video's page.

Under the Apache module, `GET /…?x+hello` gives
`$_SERVER['argv'] == ['x','hello']` but leaves the **global `$argv` NULL**,
because the module populates only the superglobal. `$argv[1]` is null, the
scripts `die()` on their own first line, and nothing happens.

Under the CGI and FPM SAPIs the global *is* populated. So
`zz-clipbucket.ini` sets `register_argc_argv = Off`, which is what
`php.ini-production` does anyway, costs nothing, and makes this inert whatever
SAPI the platform moves to. ClipBucket's own CLI workers are unaffected: the
CLI SAPI builds argv regardless of the setting.

### Upstream weaknesses, not this recipe's to fix

- **Password hashing is `hash('sha512', $password . $userid . $salt)` with no
  iterations** (`includes/functions.php:21-25`). The salt is at least generated
  per install, by `configs.sql`'s
  `SUBSTRING(HEX(SHA2(CONCAT(NOW(),RAND(),UUID()),512)),1,32)`, so it is not
  shared between accounts — but this is a fast hash over a short input and it
  is 2007's answer to the question. Changing it would mean forking the
  application.
- **`save_subtitle_ajax()` checks permission but not ownership.** It calls
  `User::getInstance()->hasPermissionAjax('edit_video')` and then acts on
  whatever `$_POST['videoid']` it was given (`includes/functions.php:3639-3684`),
  where the sibling `subtitle_delete_core.php` does compare
  `$data['userid']` against the current user. So any user who may edit their
  own videos can attach a subtitle to somebody else's.
- **The supported deployment is nginx.** `System::is_nginx()`, the vhost-version
  banner in the admin area and `docker/nginx-clipbucket.conf` all describe a
  server this platform does not run. The Apache `.htaccess` upstream ships is
  complete and was exercised throughout this work; the recipe writes the
  `nginx_vhost_version`/`revision` config rows the wizard would have written so
  the banner stays quiet.

## The php.ini, and a claim that is easy to get wrong

The base image loads no php.ini at all — `php --ini` answers
"Loaded Configuration File: (none)" — so what is in force is PHP's compiled-in
defaults. The compose override sets `PHP_INI_SCAN_DIR` to the image's own
`conf.d` **plus** a directory in the checkout, and
`files/panelalpha/php/zz-clipbucket.ini` is read from it by the Apache module
and the CLI both.

The obvious claim — "post_max_size is 8M so a video upload is discarded before
PHP is entered" — is **wrong here**, and it is worth saying so because it is
true of most applications. ClipBucket V5 chunks by default:
`enable_chunk_upload` is `yes` and `chunk_upload_size` is 2 (MB) in
`configs.sql`, so the uploader posts 2 MB at a time and a 2 GB video would in
fact get up under an 8M `post_max_size`.

What the defaults actually break:

- `FileUpload::getMaxUploadSize()` returns
  `min(post_max_size, upload_max_filesize)` and that is what the upload form
  advertises and `checkUploadedSize()` enforces — so an account is capped at
  **2 MB** the moment an administrator turns chunking off, which the admin area
  offers as a checkbox.
- `System::check_global_configs()` rejects a `max_execution_time` between 1 and
  7199 (`system.class.php:661-664`) and an admin `max_upload_size` larger than
  those limits (`:667-679`), and `includes/admin_config.php:45-51` then puts a
  "your server is misconfigured" banner across **every admin page**.

So the ini sets `upload_max_filesize` and `post_max_size` to 2048M,
`max_execution_time` to 7200 (upstream's own number, and what their nginx
example uses for `fastcgi_read_timeout`), `max_input_time` to 3600,
`memory_limit` to 512M, a UTC timezone, and `display_errors = Off` with
`expose_php = Off`. The last is the only place `expose_php` can be reached —
it is `PHP_INI_SYSTEM`, so no `.htaccess` can touch it — and with it no
response carries `X-Powered-By`.

The cost of `max_execution_time = 7200` is that a wedged request can hold an
Apache worker for two hours. The container is one account's own, so that is
their worker to lose, and the alternative is a permanent misconfiguration
banner and a 2 MB ceiling the moment anyone touches the chunking checkbox.

## Where the data lives, and what a redeploy does to it

**The database survives.** It is the account's own MySQL, provisioned by
`database: mysql` — visible in the panel, openable in phpMyAdmin, inside the
account's backup.

**The media would not have.** `DirPath::get()` (`includes/constants.php:33-47`)
puts videos, original uploads, thumbnails, posters, photos, avatars,
backgrounds, logos, subtitles, the conversion queue, the mass-upload staging
area and the per-video conversion logs under `upload/files/<name>/` — inside
the checkout that every deploy re-clones — while the `cb_video` rows
naming them survive. A redeploy would leave a catalogue of videos that are no
longer there.

The compose override bind-mounts `~/.panelalpha/clipbucket/files` over
`upload/files`. That is the **xbackbone** shape (mount over the directory)
rather than the **castopod** one (symlink out of it), and the reason is that
the whole directory has to move while both the paths and the URLs keep working:
ClipBucket serves thumbnails, posters and the video files themselves as plain
static URLs under `files/...`, and upstream's own `upload/.htaccess` rules —
the `files/temp/` deny, the `files/*.php` deny, the "a missing file under
`files/` is a 404" rule — are all written against that prefix.

`hooks/prepare.sh` seeds the mount from the checkout with `cp -rn` before the
container starts, so Docker never creates it as root, the repository's own
`no_video.mp4`, `example.mp4`, `processing.jpg`, `no-photo_*.png` and
`files/.htaccess` are on it, and a version bump that adds a new default file
still gets it.

`upload/cache/` (Smarty's compiled templates and the view cache) is
deliberately *not* on the mount: it is derived data, it is rebuilt on demand,
and it is cheaper to let the re-clone throw it away.

## The upgrade path is automated

ClipBucket migrates through `cb_install/sql/<version>/M<nnnnn>.php`, one class
per revision, found and run by `AdminTool::updateDataBaseVersion()`
(`admin_tool.class.php:352-371`). In the product that is a banner an
administrator clicks; on a hosting account there is nobody to click it, and an
application whose code is newer than its schema is not safe to serve —
`actions/file_uploader.php:7` refuses uploads outright until the version row is
current, and every `Update::IsCurrentDBVersionIsHigherOrEqualTo()` call in the
application is gated on it.

`files/panelalpha-migrate.php` runs that same tool synchronously from the
upgrade stage, before Apache binds, by `initByCode()` and `launch()` — which is
what `admin_area/actions/tool_launch.php` does with `id_tool=`. If the schema
still disagrees with the checkout afterwards the deploy is failed rather than
served: the previous container keeps running and the account's data is
untouched. This is where ZenTao's recipe had to give up, and ClipBucket is
better arranged for it.

## Files

| Path | Why |
| --- | --- |
| `panelalpha.yaml` | `docroot: upload`, `database: mysql`, `system_packages` (ffmpeg, mediainfo), the setup command |
| `hooks/prepare.sh` | deletes the shipped `install.me`; seeds and protects the media mount; denies developer files |
| `files/panelalpha-setup.sh` | install/upgrade stage driver (repository root, outside the docroot) |
| `files/panelalpha-install.php` | CLI install through upstream's own SQL, `pass_code()` and `Migration::updateConfig()` |
| `files/panelalpha-migrate.php` | runs ClipBucket's own migration tool on the upgrade stage |
| `files/panelalpha/php/zz-clipbucket.ini` | upload limits, `max_execution_time`, `display_errors`, `expose_php`, `register_argc_argv` |
| `files/upload/cb_install/.htaccess` | the installer, denied |
| `files/upload/vendor/.htaccess` | asset allow-list over the Composer tree |
| `overrides/docker-compose.override.yml` | the two mounts, `PHP_INI_SCAN_DIR`, `mem_limit`, healthcheck + `ready` service |

## Known engine defects, and how this recipe meets them

The `readDocroot()` fold of `''` and `'.'` does not bite (`upload` is a real
relative path). Serving the repository root does not apply (the docroot is a
child of the repository root). Sidecar mining is avoided by declaring
`database: mysql` — and nothing here moves compose files aside, so the
"globbed away your own override" trap cannot be sprung. The host Composer
build's disabled plugins are not reached: there is no `composer.json` at the
repository root and `vendor/` is committed. Build-stage commands being dropped
is respected — the setup command is on `install`/`upgrade`, never `build`.
Declaring `extends:` with no `id:` avoids another defect. The wiped
`~/project` is the whole reason for the media mount and `~/.panelalpha`. The
missing `php.ini` is the whole reason for the ini. The probe's `Host` header
does not bite: ClipBucket builds absolute URLs from the `base_url` config row rather
than from the `Host` header (`Network::get_server_url()`,
`network.class.php:303`), so the healthcheck's `Host: 127.0.0.1` is harmless.

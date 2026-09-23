# ClipBucket V5

A self-hosted video-sharing platform — channels, collections, playlists,
photos, an admin area and an ffmpeg transcoding pipeline — in flat PHP with
MySQL underneath and no Composer at the repository root.

Upstream: <https://github.com/MacWarrior/clipbucket-v5> (5.5.3, revision 188,
`86d81659`). Tracker:
[supported-apps#1137](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/1137).

The batch verdict was `serving-missing_entry`: a deploy that finished and a
site where every request answered 403.

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
Apache answers 403 on a directory with no index, and the report says the entry
point is missing.

`docroot: upload` is the whole fix for the verdict, and it is enough on its own:
`upload` is a plain relative path, so unlike `.` it survives
`PlatformManifest::readDocroot()` (`PlatformManifest.php:297-318`), which folds
both `''` and `'.'` to "undeclared". **#172 does not bite here.** This is
exactly the case the manifest key exists for — `_schema.json` names `upload` in
its own example, for OpenCart. Adding `upload` to the probe list would be the
wrong fix: `upload/` is a directory name a great many applications use for
*uploaded content*, which is the last thing to point a document root at.

**Serving `upload/` is also what keeps the deploy's own files off the web.**
The repository root is the *parent* of the document root, so `.git/`,
`.env.default`, the generated `docker-compose.yml`, `docker-compose.override.yml`
and the `panelalpha-*` scripts are unreachable by construction rather than by
an `.htaccess` rule. **#181 does not apply.** Verified: all of them answer with
the 404 body, or with the vhost's own 403 for the names it denies by pattern.

**The database.** MySQL or nothing — `cb_install/sql/structure.sql` is MySQL
DDL, every data file beside it is a MySQL dump, and `Clipbucket_db` speaks
mysqli directly with no other driver. The checkout ships no `.env` and no
compose file at its root to point itself at a server. `database: mysql` gets
one on the account's own MySQL server.

**The installation, ffmpeg, the php.ini and where the media lives** are the
rest of this recipe and have sections of their own below.

## The ffmpeg answer

**The shared PHP base image has no ffmpeg, no ffprobe and no mediainfo, and no
recipe can add one.** Measured:

```
$ docker run --rm panelalpha/php:8.3-apache-bookworm-pa20260910 \
      sh -c 'command -v ffmpeg ffprobe mediainfo'
(nothing)
```

`requires:` in a manifest names toolchains the engine knows about;
`PhpBaseImage`'s `extras` are php extension names handed to
`install-php-extensions` (`core/app/Lib/Deploy/CacheManager/PhpBaseImage.php:254-270`);
and `core/resources/deploy/templates/dockerfile/php-base.stub` installs a fixed
`git unzip` and nothing a manifest can reach. There is no per-project
Dockerfile any more — the stub says so itself.

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

So `hooks/prepare.sh` fetches static builds once per account into
`~/.panelalpha/clipbucket/bin`, which the compose override mounts at
`/data/bin`:

| Tool | Source | Size |
| --- | --- | --- |
| ffmpeg, ffprobe 7.0.2 | `johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz` | 42 MB down, 160 MB on disk |
| mediainfo 26.05 | MediaArea's `MediaInfo_CLI_26.05_Lambda_x86_64.zip` | 6.6 MB down, 17 MB on disk |

Both verified to run inside the bookworm base image before being wired in.
MediaArea's Lambda build is the only self-contained MediaInfo CLI they publish
— their Debian packages need `libmediainfo0v5` and `libzen0v5` unpacked beside
them. The install then writes those paths into the `ffmpegpath`,
`ffprobe_path` and `media_info` config rows, which `System::get_binaries()`
reads *before* it ever falls back to `which`
(`includes/classes/system.class.php:547-620`).

Once per account, not once per deploy: `~/.panelalpha` outlives the re-clone
(#173). A failed **ffmpeg** download fails the deploy — a video site that
cannot accept a video should not be reported as a success. A failed
**mediainfo** download does not: it is a hard requirement of upstream's
precheck but only a fallback at runtime, used for the duration when ffprobe
could not give one and for the "Original width/height" of anamorphic material
(`ffmpeg.class.php:126,134`), and ffprobe covers everything else.

**This is a workaround and the right fix is in the base image.** Every
video-, audio- or image-processing application on this platform will want
ffmpeg, and the alternative to putting it there is every recipe downloading
160 MB per account from a third party. See "Engine findings" below.

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
queue off its own traffic. Measured end to end below.

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
by `files/panelalpha-install.php` with a password generated per account into
`~/.panelalpha/clipbucket/admin-credentials` (0600 in a 0700 directory, because
account homes are root-owned 0755). `add_admin.sql` seeds `userid = 1` with an
*empty* password and the seed phase fills it in, which is also why the install
check requires `password <> ''` — a half-finished install must not look
finished.

### What is reachable in the document root without a session

**Read this section's results carefully, because status codes lie here.**
Upstream's `upload/.htaccess` sets `ErrorDocument 404 /404` and
`ErrorDocument 403 /403`, which render ClipBucket's own pages, and
302-*redirects* several families of path to `/403` rather than denying them. A
status-code-only probe therefore reports a mixture of 200, 302, 403 and 404
that means very little. Every result below is a body compared against two
baselines: the 404 page (`md5 a0f277d6…`, 27,199 bytes) and the 403 page
(`md5 95e8706b…`, 27,085 bytes).

Reachable on a first deploy, and closed by this recipe:

| Path | What it was |
| --- | --- |
| `/cb_install/*` | the installer, above |
| `/vendor/composer/installed.json` | 200, 35,941 bytes — the exact installed version of every dependency, which is a CVE shopping list |
| `/vendor/**/*.php` | `vendor/filp/whoops/src/Whoops/Run.php` answered **500 with an empty body**, which is Apache *executing* a library file out of context |
| `/vendor/smarty/smarty/composer.json` etc. | 200, package metadata throughout the tree |
| `/files/logs/<date>/<file_name>.log` | 200, 5,991 bytes — the per-video conversion log: absolute container paths, every source and output stream's codec and bitrate. `file_name` is in the page HTML of every video, so these are *enumerable*, not merely reachable |
| `/composer.json`, `/package.json` | 200 — the dependency set and the exact version |

`vendor/` cannot simply be denied: ClipBucket installs three frontend libraries
with Composer and links them straight out of it on every page and in the admin
area — `components/jquery`, `select2/select2`, `fortawesome/font-awesome` and
its fonts. So `files/upload/vendor/.htaccess` is an **allow-list**: a request
under `vendor/` is served only if it ends in an asset extension, and everything
else is 403. A deny-list would have to guess at every extension a Composer
package might ship and would be wrong on the next dependency.

After the change, re-measured on the live deploy: `installed.json`,
`Whoops/Run.php`, `/composer.json`, `/package.json`, the conversion log and
`/cb_install/` are all 403, and jQuery (87,533 bytes), select2, font-awesome
and its `.woff2` still serve 200.

What upstream already gets right, and it is a decent amount:

- `includes/`, `changelog/` and `files/temp/` are 302'd to `/403` — so
  `includes/config.php`, which holds the database configuration, is not
  reachable even though it is inside the document root.
- `admin_area/` and every page under it redirect to the login for an
  anonymous request.
- **A `.php` file under `files/` is bounced before Apache can run it.**
  Measured: a `pa-probe.php` written into `files/avatars/` answered 302 to
  `/403`; the same file named `.php.jpg` was served as plain text and not
  executed. `files/.htaccess`'s `AddHandler cgi-script` + `Options -ExecCGI` is
  the belt, the parent's `RewriteRule ^(.*/)?files/.*\.php` is the braces.
- Directory listings are off everywhere (`Options -Indexes` in the vhost).

### `register_argc_argv`, checked and found inert — here

ClipBucket ships CLI-only entry points *inside the document root* with no
`php_sapi_name()` guard: `actions/video_convert.php` and
`actions/verify_converted_videos.php` both begin `$argv[1] ?? false`. With
`register_argc_argv` on — and the base image, having no php.ini at all, leaves
it at the compiled-in `On` — a query string containing no `=` is split on `+`
into argv. `GET /actions/video_convert.php?x+<file_name>` looks like an
unauthenticated way into the conversion driver with a `file_name` that is
public in every video's page.

Measured on the live deploy: `GET /pa-probe.php?x+hello` gives
`$_SERVER['argv'] == ['x','hello']` but leaves the **global `$argv` NULL**,
because the Apache module populates only the superglobal. `$argv[1]` is null,
the scripts `die()` on their own first line, and nothing happens — confirmed by
calling the real URL against the real video and finding its status, its queue
row and its log unchanged.

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

The base image loads no php.ini at all (#185) — `php --ini` answers
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
  "your server is misconfigured" banner across **every admin page**. Measured
  at `max_execution_time = 600`: the banner is there. At 7200: it is gone.

So the ini sets `upload_max_filesize` and `post_max_size` to 2048M,
`max_execution_time` to 7200 (upstream's own number, and what their nginx
example uses for `fastcgi_read_timeout`), `max_input_time` to 3600,
`memory_limit` to 512M, a UTC timezone, and `display_errors = Off` with
`expose_php = Off`. The last is the only place `expose_php` can be reached —
it is `PHP_INI_SYSTEM`, so no `.htaccess` can touch it — and it works:
verified, no `X-Powered-By` on any response.

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
the checkout that every deploy re-clones (#173) — while the `cb_video` rows
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

**Verified with a real redeploy**, not reasoned about: a video uploaded and
transcoded, then `POST /projects/<u>/rebuild`, then the same video's watch page,
its 360p rendition (135,895 bytes) and its thumbnails all still served over the
public HTTPS domain, and the generated admin password still logging in.

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

**Exercised only as a no-op.** The redeploy above re-ran the upgrade stage and
logged `schema 5.5.3.188 matches the checkout`; an actual version bump was not
tested, because `master` is the only branch that carries 5.5.3 and there is
nothing newer to move to.

## What was verified

On `mariusz.panelalpha.tools`, 2026-09-20, ClipBucket 5.5.3 rev 188
(`86d81659`), PHP 8.3, `--memory-limit=2000`, **host load average 6.7–8.1
throughout** (a long batch job was running, so these numbers are slow):

- `deploy-ok`, `serving: ok`, HTTP 200 on the public domain, every baseline
  and `php` health check passing, **75 s** for a first deploy from nothing,
  **21 s** for a rebuild.
- **Logged in over the public HTTPS domain with the generated credential** and
  rendered authenticated pages: `/my_account` → `My Account - ClipBucket`,
  `/admin_area/` → `ClipBucket - Administration Panel` with its dashboard.
  Anonymous `/my_account` is a 302.
- **The admin's own System Info page finds every tool**: FFmpeg 7.0.2,
  FFprobe 7.0.2, MediaInfo 26.05, Git 2.39.5, MySQL 12.2.2,
  `post_max_size`/`upload_max_filesize` 2048M, `exec()` and `shell_exec()`
  enabled.
- **A real video upload, end to end.** A 6-second 640×360 H.264/AAC MP4 posted
  to `actions/file_uploader.php`; `{"success":"yes","videoid":1}`; the queue row
  picked up by the `user_activity` automation 50 seconds later; ffmpeg produced
  240p and 360p renditions and 25 WebP thumbnails in **4.8 seconds**; the video
  went to status `Successful` with `convert_percent` 100 and its duration read
  back as 6. The watch page renders it, the anonymous `/videos` listing shows
  it, and `/files/videos/2026/09/20/<name>-360.mp4` serves 135,895 bytes of
  `video/mp4` over the public domain.
- **The redeploy**, and the video surviving it (above).
- The exposure table above, before and after the hardening, with bodies
  compared rather than status codes.
- The `register_argc_argv` question, answered by probe rather than by reading.
- **The finished recipe re-run from nothing on a second account**, after the
  hardening and the ini were added: `deploy-ok`, `serving: ok`, 75.2 s, no
  failing health check, the admin panel rendering with **no misconfiguration
  banner**, `/cb_install/`, `/vendor/composer/installed.json` and
  `/composer.json` all 403 while jQuery still serves, `install.me` absent from
  both the checkout and the mount, and a second video uploaded, transcoded to
  240p/360p and served over the public domain.

### The multipart-POST stall is real (#170)

The upload had to be retried against the account's own address.
`POST /actions/file_uploader.php` with a 97 KB `multipart/form-data` body
through `https://<account>.panelalpha.online` **hung for exactly 60 seconds and
came back as a 302 to an openresty error page**. The identical request to the
account container's own `:8000` with a `Host:` header returned in 39
milliseconds. Nothing about ClipBucket is involved; do not read it as an
application fault.

### Not verified

- An actual ClipBucket version upgrade (nothing newer than 5.5.3 exists to
  upgrade to).
- Photo upload, collections, playlists, comments and the mail paths.
- HLS conversion (`conversion_type` is `mp4` by default; the HLS branch of
  `video_convert.php` was read but not run).
- Behaviour when the ffmpeg download fails — the failure path is written and
  reviewed but was not forced.

## Files

| Path | Why |
| --- | --- |
| `panelalpha.yaml` | `docroot: upload`, `database: mysql`, the setup command |
| `hooks/prepare.sh` | deletes the shipped `install.me`; generates the admin password; fetches ffmpeg/ffprobe/mediainfo; seeds and protects the media mount; denies developer files |
| `files/panelalpha-setup.sh` | install/upgrade stage driver (repository root, outside the docroot) |
| `files/panelalpha-install.php` | CLI install through upstream's own SQL, `pass_code()` and `Migration::updateConfig()` |
| `files/panelalpha-migrate.php` | runs ClipBucket's own migration tool on the upgrade stage |
| `files/panelalpha/php/zz-clipbucket.ini` | upload limits, `max_execution_time`, `display_errors`, `expose_php`, `register_argc_argv` (#185) |
| `files/upload/cb_install/.htaccess` | the installer, denied |
| `files/upload/vendor/.htaccess` | asset allow-list over the Composer tree |
| `overrides/docker-compose.override.yml` | the two mounts, `PHP_INI_SCAN_DIR`, `mem_limit`, healthcheck + `ready` service (#90) |

## Engine findings

Nothing new filed. Two things worth someone's attention:

**ffmpeg belongs in the shared PHP base image.** This recipe downloads 160 MB
of static binaries per account from a third party because there is no other
lever — `extras` are php extensions, `php-base.stub`'s apt line is fixed, and
there is no per-project Dockerfile. Every video, audio or image application
this platform meets will want the same thing, and `castopod`'s recipe already
records losing its video-clip feature to the same gap. An `extras`-shaped key
for apt packages, or simply `ffmpeg` in the stub, would remove the workaround
from this recipe and the next five.

**Known defects met, and how.** #172 does not bite (`upload` is a real relative
path). #181 does not apply (the docroot is a child of the repository root).
#166 is avoided by declaring `database: mysql`, which stops sidecar mining —
and nothing here moves compose files aside, so the "globbed away your own
override" trap cannot be sprung. #168 is not reached: there is no
`composer.json` at the repository root and `vendor/` is committed. #171 is
respected — the setup command is on `install`/`upgrade`, never `build`. #169 is
avoided by `extends:` with no `id:`. #173 is the whole reason for the media
mount and `~/.panelalpha`. #185 is the whole reason for the ini. #190 does not
bite: ClipBucket builds absolute URLs from the `base_url` config row rather
than from the `Host` header (`Network::get_server_url()`,
`network.class.php:303`), so the healthcheck's `Host: 127.0.0.1` is harmless.
#170 was hit squarely and is written up above.

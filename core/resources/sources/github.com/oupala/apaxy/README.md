# Apaxy

Upstream: <https://github.com/oupala/apaxy> · tracker:
panelalpha/playground/supported-apps#798

Apaxy is a theme for Apache's `mod_autoindex`: a `.htaccess`, a stylesheet, a
header and footer fragment, and ~90 SVG icons. It has no program of its own.
What makes it an application here is the thing upstream ships beside the
theme — a `Dockerfile` and a `docker-compose.yml` that stand it up as a
**file-share site**: Apache at the web root, a directory bind-mounted into it,
and `share/PLACE_YOUR_FILES_HERE.txt` to say what the directory is for. This
recipe is that arrangement on a PanelAlpha account.

## The recorded verdict was the application working

`serving-directory_listing`, HTTP 200, 30.1s — the only row in the sweep with
that verdict. Reproduced here at 33s (control account, stock repository, no
recipe): `serving: directory_listing`, HTTP 200, 103,516 bytes, the theme fully
applied.

The check that fired is `_baseline/no-directory-listing`, and its premise —
"a listing means the server found the directory and no document in it" — is
exactly inverted for the one application whose product *is* the listing. It
matches on `<title>Index of /`, which is written by `mod_autoindex`'s own HTML
preamble; upstream's `apaxy/htaccess.txt` keeps that preamble
(`-SuppressHTMLPreamble`) and injects into it, so Apaxy could never not
trip it.

This recipe sets `+SuppressHTMLPreamble` and gives `HeaderName` a full
document to emit, which is both how the site gets a title of its own and why
the check now passes. That is a real improvement — a file share called
"Index of /" is the server's name for it, not the owner's — but it should be
read honestly: the shipped check is still measuring the wrong thing, and no
recipe can say so, because `_baseline` runs for everything
(`CheckRegistry::for()`, `core/app/Lib/Deploy/Health/CheckRegistry.php:79-80`)
and a recipe check may not shadow or guard a shipped one (`:171-177`).

## What the deploy does

Detection is unchanged and correct: `compose` is skipped because upstream's
compose `build:`s and bind-mounts `./share`, which
`ComposeFileInspector::isLocalDevCompose()` reads as a workstation file
(`core/app/Lib/Deploy/Compose/ComposeFileInspector.php:327`,
`serviceBindsProjectRoot()`), so `dockerfile` (priority 970) claims it and
builds upstream's image. Measured: `strategy: dockerfile`, `via: recipe`,
`dockerfile_expose: 8080`.

The recipe adds two things.

**`overrides/docker-compose.override.yml`** mounts
`../.panelalpha/apaxy/files` over `/usr/local/apache2/htdocs`. Without it the
document root is whatever upstream's Dockerfile baked — and what it bakes is a
`touch` loop over ~350 `example.<ext>` files, an icon showroom. That is what
the control account serves, and no file the owner adds can ever reach it: the
generated compose for the `dockerfile` strategy declares no volumes at all
(`DeployCompose::dockerfile()`, `core/app/Lib/Deploy/Compose/DeployCompose.php:95-140`),
and the Dockerfile's second stage copies only `/var/www/html` out of the
first.

**`hooks/prepare.sh`** installs the theme into that directory — `theme/` and a
`.htaccess` generated from upstream's `htaccess.txt` — and nothing else. It
never touches anything else in the document root. Upstream's own
`apaxy-configure.sh` is deliberately not used: it ends with
`find ${installDir} -name "*.html"` and `sed -i` over every match, which on a
redeploy would rewrite the owner's own HTML files.

## Where the owner's files live, and why there

`~/.panelalpha/apaxy/files`. This is the load-bearing decision and it was
forced.

For most applications engine#173 — a rebuild clearing `~/project` — costs
configuration. Here the checkout *is not* the data; the owner's uploads are,
and they have to be inside the document root. So the document root cannot be
in `~/project`:
`GitRepository::cloneConfiguredRepository()` calls
`ProjectTree::clearContents()`
(`core/app/System/Project/Dind/Source/GitRepository.php:89`), which is
`find ~/project -mindepth 1 -maxdepth 1 -exec rm -rf {} +`
(`core/app/System/Project/Dind/Source/ProjectTree.php:31-35`).

Measured: a file written to `~/project` before
`POST /projects/apxrcp/rebuild` was gone after it; every file in
`~/.panelalpha/apaxy/files` survived, and the site served them again.

The first attempt used `~/files`, and the deploy failed with
`mkdir: cannot create directory '/home/apxrcp/files': Permission denied`. The
account home is `chown root:root` on every rebuild
(`core/app/System/Project.php:813`) and the account owns only what the engine
scaffolds in it. `~/.panelalpha` is one of those (`:814`), is owner-owned, and
is not `~/project` — which makes it the only writable, rebuild-surviving
directory a recipe can use. A named volume, which is how every other recipe
here persists data, is not an option: nothing in the product can put a file
*into* one.

Measured over SFTP: `apxrcp_up@…:2222`, `cd .panelalpha/apaxy/files`, `put` —
the file was listed and downloadable over HTTPS immediately, with no rebuild.
The engine's SFTP server lists dotted directories, so the path is navigable
rather than only typeable.

## Exposure

The document root is published in full, by name, to anyone, with no
authentication. `mod_autoindex` has none and this recipe adds none. That is
the application, not a defect, but it is the first thing to tell an owner:
**everything in `~/.panelalpha/apaxy/files` is public.**

Measured on the deployed site (bodies, not just codes):

| Request | Result |
|---|---|
| `/theme/`, `/theme/icons/` | **403**, 413 B — Apaxy's themed 403. `apaxy/theme/.htaccess` sets `Options -Indexes` |
| `/.htaccess`, `/theme/.htaccess` | **403**, 413 B |
| `/docker-compose.yml`, `/.env` | **404**, 423 B — they are in `~/project`, which is not under the document root at all |
| `/../project/docker-compose.yml` | **404** (normalised) |
| `/..%2fproject/` | **400** |
| `escape.txt` → symlink to `/etc/passwd` | **403**, and **not listed** — `Options -FollowSymLinks` |
| `private.txt`, mode 0600 | **listed by name**, body **403** |
| `probe.php` | **200, served as source** — there is no PHP here and `-ExecCGI` is set |

Two of those deserve a sentence.

A file the account can read but Apache cannot — anything not world-readable —
**still appears in the listing** and 403s on click. `mod_autoindex` lists
directory entries without asking whether they can be served, so a mode-0600
file leaks its name. The prepare hook runs `chmod -R a+rX` on the document
root at deploy time, which fixes what is there; files uploaded later keep
whatever mode the client set. Upstream's image serves as uid 1000 and the
account is not uid 1000, which is why world-readable is the requirement.

An `index.html` the owner uploads **silently replaces the whole site**:
measured, `/` went from 2,403 bytes of listing to the 21-byte file. Apache
serves the index and never calls `mod_autoindex`. Deleting it restores the
listing.

`theme` and `.htaccess` are kept out of the listing by `IndexIgnore`. Upstream
writes that as `IndexIgnore .htaccess /theme`, and the `/theme` form never
matches anything — an `IndexIgnore` pattern is tested against the bare
filename — so the hook adds the bare `theme`. Under stock `httpd:2.4` with the
`.htaccess` ignored entirely, the same directory lists `theme/` as an ordinary
row.

## Verified

Recipe account, 31s (preparing 9s, cloning 4s, running 18s): `deploy-ok`,
`serving: ok`, HTTP 200 on the public HTTPS domain, all seven `_baseline`
checks passing.

Past the probe, over the real domain: the listing renders with the theme (dark
table, per-MIME icons — `application-pdf.svg` for `report.pdf`,
`text-richtext.svg` for `.md`, `folder.svg`, `user-home.svg` for the parent
row — folders first, a working breadcrumb built by `apaxy.js`, no console
errors); `style.css` 8,560 B, `apaxy.js` 3,203 B, `favicon.ico` 5,430 B and
the SVG icons all 200 with correct content types; `/photos/` and `/docs/`
navigate and render; `notes.txt` and `photos/a.txt` download with their exact
contents; `/nope` returns Apaxy's themed `Error 404`.

The proof that the theme is in effect, rather than that a listing appeared, is
a negative control: stock `httpd:2.4` (`AllowOverride None`, so the
`.htaccess` is inert) serving the **same directory** returns 430 bytes of bare
`<ul>` titled `Index of /`, with `theme/` exposed and no icons or stylesheet.

Memory: the application container idles at **6.4–9.3 MiB**; the account
container at **126 MiB**. The built image is 206 MB over a 177 MB `httpd:2.4`.

## Known: the recipe's own check never runs

`checks/_baseline/apaxy-theme-applied.yaml` asks the positive question
`no-directory-listing` cannot — is this Apaxy's listing or Apache's bare one.
It is correct and it is never asked.

`deploy_checks_dir` is computed and persisted
(`core/app/System/Project/Dind/PrepareFromSource.php:110-112`; measured on the
account as `…/sources/github.com/oupala/apaxy/checks`), and
`CheckRegistry::allWithDirectory()` merges the directory's groups. But
`AppHealth::runChecks()` calls `CheckRunner::for($runtime, …)`
(`core/app/System/Project/Dind/AppHealth.php:318`), the two-argument form,
which passes no directory. `CheckRunner::forWithDirectory()`
(`core/app/Lib/Deploy/Health/CheckRunner.php:53`) exists for exactly this and
has **no production caller** — only two unit tests. So `deploy_checks_dir` is
written and never read.

Measured in the core container: `allWithDirectory($dir)` returns the
`_baseline` group with `apaxy-theme-applied` appended; `all()` returns it
without. The live health report matched the second. The check is left in place
because it is right and costs nothing; it will start being asked when that one
call is changed.

## Not covered

* `enableGallery` (lightgallery.js) is off, as upstream ships it. It pulls CSS
  and JS from a CDN, which is a third party in every page the account serves.
* No authentication. If a share should be private, this is the wrong
  application — `mod_autoindex` has no concept of a user.
* The header and footer messages upstream exposes as
  `{HEADER-MESSAGE}` / `{FOOTER-MESSAGE}` are blanked. Making them settable
  per account would mean an `env:` block and a rebuild to apply, which is a
  poor trade for a caption.
* A symlink out of the document root returns Apache's stock 403 rather than
  Apaxy's themed one; the denial happens before the `.htaccess` `ErrorDocument`
  applies.

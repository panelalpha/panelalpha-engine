# ESMira

<https://github.com/KL-Psychological-Methodology/ESMira> — AGPL-3.0, PHP, no
database server.

ESMira runs longitudinal psychology studies (experience sampling, ambulatory
assessment, EMA) on the researcher's own server. It is two halves: a web
interface where a researcher designs a study, publishes it and reads the
collected data, and a JSON API that the Android and iOS clients talk to.
Everything it stores — the studies, every participant response, the researcher
accounts and their bcrypt password hashes — is a plain file on disk.

That last sentence is why this directory is longer than most. The default
arrangement puts those files inside the served document root, and the engine
empties `~/project` before every clone.

---

## Why the generic platform fails

Without this directory the deploy succeeds (`php-plain`, PHP with no Composer)
and every request answers 403: Apache's `Options -Indexes` on a directory with
no `index.php`. The full chain:

1. **The repository is a meta repository.** The root holds four files, an
   `about/` directory of screenshots and a `.gitmodules`. The server is the
   `ESMira-web` submodule; `ESMira-apps` is the phone client.
2. `PhpSources::present()` walks two levels down
   (`Detect/PhpSources.php:16`), finds `ESMira-web/src/index.php` at exactly
   depth 2, and `php-plain` claims the project — correctly: this *is* PHP with
   no Composer.
3. `PhpDocroot::detect()` (`Platform/Runtime/Php/PhpDocroot.php:79-95`) tries
   `public`, `web`, `public_html`, `webroot`, then the repository root, then
   `src/index.php`. The front controller is two levels down and the root has
   no index at all, so it returns `''` and `environment()` (line 59) emits no
   `PA_DOCROOT`.
4. `panelalpha-serve.sh` (lines 18-27) sees no `/app/public` and falls back to
   `/app`.
5. `apache-vhost.stub:19` — `Options -Indexes`, no DirectoryIndex match, 403.

Pointing the document root at `ESMira-web/src` does not help either:
that is the *unbuilt* source. The deployable tree only exists after a webpack
build.

## What the recipe changes

Four things, in the order the deploy hits them.

### 1. The submodule must be there, and a warning is not enough

`GitRepository::fetchSubmodules()`
(`System/Project/Dind/Source/GitRepository.php:589-607`, calling
`initSubmodules()` at line 137) runs `git submodule update --init --depth=1
--recursive` after the clone and
turns any failure into a `warn` line. That is the right call for a repository
whose submodules are optional and the wrong one here, where an empty
`ESMira-web/` means there is no application at all — the deploy would go on to
build nothing and serve a 403 that looks exactly like the failure above.

`hooks/prepare.sh` checks for `ESMira-web/src/index.php` and exits 1 with a
message that says what happened.

A submodule pin that moves off its default-branch tip is exactly the case where
`--depth=1` stops working.

### 2. The application has to be compiled

`npm run prod` runs webpack, which writes `dist/`. The PHP half of the
application is not left behind in `src/`: CopyWebpackPlugin copies
`src/backend`, `src/api`, `src/locales` and `src/.htaccess` *into* `dist/`
beside the bundles (`build_configs/config.base.js`), and HtmlWebpackPlugin
renders `src/index.php` into `dist/index.php` with the hashed asset names
injected. So `dist/` is the deployable tree and `src/` is never servable.

The engine already knows how to do this: `HostCompile::runForPhp()` compiles a
PHP project's frontend on the host, in the account's sandbox, with the shared
npm cache at `/var/cache/pa-js/npm` and the account's memory cap. It fires on
exactly two conditions — a `package.json` at the **project root** with a
`scripts.build` (`HostCompile.php:188-201`) — and ESMira satisfies neither: its
package.json is one level down, and its production script is called `prod`.

`files/package.json` is a four-line shim that satisfies both and delegates:

```json
"build": "cd ESMira-web && npm install --no-audit --no-fund --loglevel=error && npm run prod"
```

`npm install`, not `npm ci`, and that is not stylistic: at the
pinned submodule commit `package-lock.json` is out of sync with `package.json`
(`npm error Missing: @types/semver@7.8.0 from lock file`), so `npm ci` fails
outright. The engine would have chosen `ci` itself — `JsPackageManager::installCommand()`
picks it whenever a lockfile is present — which is another reason the build
goes through a script the recipe writes rather than through the engine's
default command.

`node_modules` is inside `~/project` and is therefore rebuilt on every deploy;
the shared host npm cache is what makes that cheap.

### 3. The first-run setup is first-visitor-wins

`InitESMira` is registered in `api/admin.php` under `//no permission:` and its
only guard is `Configs::getDataStore()->isInit()`. On a server that has never
been set up, this is enough to own it and everything that will ever be
collected on it:

```
POST /api/admin.php?type=InitESMira
new_account=me&pass=…&data_location=/var/www/html
```

No authentication, no token, no rate limit. On an account with a public HTTPS
domain the window is however long it takes the owner to open a browser.

`files/panelalpha-esmira-setup.php` closes it on the install stage, before
Apache binds, by driving the wizard's own code —
`ESMiraInitializerFS::getConfigAdditions()`, `FileSystemBasics::writeServerConfigs()`,
`ESMiraInitializerFS::create()` — rather than reimplementing any of it. The
login is generated by the engine (`credentials:` in `panelalpha.yaml`),
returned by `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`)
and reaches the container from `~/.panelalpha/app-credentials.env` through
`env_file:`. An account deployed before this keeps its password from
`~/.panelalpha/esmira-app.env` (`adopt_from`).

The compose override's healthcheck then asserts on **every** probe that the
endpoint stayed closed. The probe POSTs an empty body deliberately: InitESMira
checks `isInit()` before it checks `isset($_POST['new_account'])`, so a probe
carrying a plausible account name would *perform the install* on any server
where the first check passed — the health check would become the attack it
exists to detect.

On the install stage the deploy log reads:

```
[esmira] installed: data folder /data/esmira_data/, admin account 'admin', server version 3.7.0
[esmira] the first-run wizard is now closed (InitESMira answers 'Disabled')
```

### 4. The data is files, and by default they are inside the document root

ESMira's wizard offers `DIR_BASE` as the data location and upstream's own
Dockerfile takes it (`VOLUME /var/www/html/esmira_data/`). The shipped
arrangement therefore has, inside the served tree:

```
esmira_data/.logins                         admin:$2y$10$…   (bcrypt)
esmira_data/.permissions
esmira_data/studies/<id>/.config.json       the study definition
esmira_data/studies/<id>/responses/*.csv    every participant response
esmira_data/studies/<id>/.userdata/<b64>    per-participant state
esmira_data/studies/<id>/media/…            participant photos and audio
```

protected by one `.htaccess` holding `Deny from all` that
`ESMiraInitializerFS::createDataFolder()` writes. That is one `AllowOverride`
and one Apache-2.4 `mod_access_compat` away from being a no-op, on research
data about people.

It also would not survive: `~/project` is emptied before every clone.

`dataFolder_path` is a **config** value (`backend/fileSystem/PathsFS.php:21`),
not a constant, so the recipe points it at `/data` — a bind mount of
`~/.panelalpha/esmira/data`. That removes the URL instead of denying it, and
keeps the studies across redeploys.

`backend/config/configs.php` is the one file that cannot be moved:
`Paths::FILE_CONFIG` is a class constant pinned to `DIR_BASE`
(`backend/Paths.php:8`). It is bind-mounted from `~/.panelalpha/esmira/config`
into the document root, which is exactly where upstream's Dockerfile mounts it.
It holds the data folder path, the server name and the legal texts — no
credential.

---

## Exposure

Compare bodies, not status codes: ESMira has a front controller and its
`.htaccess` rewrites `^([a-zA-Z][a-zA-Z0-9]+)$` to `index.php?key=$1`, so
`/VERSION` and `/STRUCTURE` answer 200 with the application's front page rather
than with the file.

| Path | Result |
|---|---|
| `/esmira_data/…`, `/data/esmira_data/…`, `/studies/<id>/responses/…` | 404 — not under the document root at all |
| `/backend/`, `/backend/config/configs.php`, `/backend/Configs.php` | 403 (`Deny from all`, `access_compat_module` is enabled) |
| `/.logins`, `/.permissions`, `/.htaccess`, `/.env` | 403 (`<FilesMatch "^\.(?!well-known)">` in the vhost) |
| `/docker-compose.yml`, `/panelalpha-esmira-setup.php` | 403 (`<FilesMatch "^(?:docker-compose\.ya?ml\|panelalpha[-.])">`) |
| `/VERSION`, `/STRUCTURE`, `/LICENSE` | 200 — the front page, by rewrite; not the file |
| `/README.md`, `/CHANGELOG.md` | 200 — upstream's own public documentation |
| `/src/frontend/ts/*.d.ts` | 200 — TypeScript declarations ts-loader emits into `dist/`; the public frontend API, no secrets |

One thing is public by upstream's design and is worth knowing about:
`/api/server_statistics.php` answers unauthenticated with aggregate counts —
number of studies, number of participants, joins and questionnaires per day. It
is what the application's own "server statistics" page renders. No study title,
no participant id, no response.

## Redeploy

On a redeploy `~/project` is wiped and re-cloned,
`ESMira-web/node_modules` is rebuilt from the host npm cache, `dist/` is
recompiled — and `~/.panelalpha/esmira/` is untouched, so `PA_DEPLOY_PHASE`
becomes `upgrade` and the setup script logs

```
[esmira] already initialised (/data/esmira_data/); checking migrations
[esmira] migrations up to date
```

`MigrationManager::autoRun()` is the same call upstream's
`docker-entrypoint.sh` makes on every boot.

The one thing a redeploy can break is a stored login that has drifted from the
account: if the data folder survives while the engine's stored login does not
(an account moved between hosts, say), the setup script skips the install
because the server is already initialised, and the stored login then opens
nothing. It cannot be repaired from there — the stored form
is bcrypt — so the setup script checks `checkAccountLogin()` on every upgrade
and says so in the deploy log.

## Operating notes

- The account's whole state is two directories:
  `~/.panelalpha/esmira/data/esmira_data/` and `~/.panelalpha/esmira/config/`.
  Copy those to back it up; nothing else in the account holds research data.
- The administrator login is returned by `GET /projects/{name}/app-credentials`.
  Changing it in the web interface does not update that, and the setup script
  never touches an account that already exists.
- **Do not use the in-app "update server" feature.** ESMira can download a
  release zip and rewrite its own document root in place
  (`admin/features/adminPermission/UpdateStep*`). That works, and the next
  redeploy silently undoes it, because the document root is a git checkout the
  engine re-clones. Redeploy the project instead; the version moves with the
  submodule and `MigrationManager` runs on the upgrade stage.
- `ESMira-apps` (the Kotlin Multiplatform phone client) is cloned and never used. It is
  left in place so `git status` stays clean; it is outside the document root
  and has no URL.
- The compose override gives the app container 512 MB.

## Licence

AGPL-3.0 (`LICENSE`; `package.json` says "GNU AGPL 3.0"). The network-service
clause applies: a hosted instance must offer its users the source. The
application is deployed unmodified from its own public repository and that URL
is in the checkout, so pointing at upstream satisfies section 13. This recipe
patches nothing in the application — every file it writes is configuration or
sits outside the application's tree, and the one PHP file it adds is not
served.

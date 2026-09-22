#!/bin/bash
# Account shell, after the clone and before detection. The recipe's files/ --
# panelalpha/hubleys-auth.conf -- is already beside the application.
#
# Hubleys is a dashboard that reads one YAML file and shows each user the tiles
# their groups are allowed to see. Everything about hosting it comes down to
# two facts:
#
#   1. It authenticates nobody. src/hooks.server.ts takes the user identity
#      from Remote-User / Remote-Groups / Remote-Name / Remote-Email and
#      trusts them, and `error(500, 'forward auth not configured')` on line 56
#      is what a request with no Remote-User gets. That 500 is the whole
#      stock-deploy verdict; sending the header is the whole bypass.
#   2. Everything it keeps -- config.yml, per-user settings, uploaded
#      wallpapers -- lives under /data, which upstream's Dockerfile declares
#      as a VOLUME. The engine honours that with a named volume, which
#      survives a redeploy and which nothing in the product can put a file
#      into: the customer could never edit the config that *is* the product.
#
# So this script does three things: writes a dashboard to start from, puts it
# somewhere a redeploy cannot reach and the account can edit, and writes the
# compose override that puts the missing authenticator in front.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/hubleys"
DATA_DIR="${DATA_HOME}/data"
AUTH_DIR="${DATA_HOME}/auth"
CONFIG="${DATA_DIR}/config.yml"

say() { echo "[panelalpha] hubleys: $*"; }

if [ ! -f svelte.config.js ] || [ ! -f src/hooks.server.ts ]; then
    echo "[panelalpha] hubleys: no src/hooks.server.ts -- this is not a hubleys-dashboard checkout" >&2
    exit 1
fi

# ~ itself is chown root:root on every rebuild (Project.php:813) and the
# account can create nothing directly in it; ~/.panelalpha is one of the few
# things the engine scaffolds for the account (:814), so it is the only place
# a generated file survives a redeploy AND stays editable by the customer.
mkdir -p "${DATA_DIR}/users/config" "${DATA_DIR}/users/backgrounds" \
         "${DATA_DIR}/logos" "${DATA_DIR}/wallpaper" "${AUTH_DIR}"
chmod 700 "${DATA_HOME}" "${AUTH_DIR}"

# ---------------------------------------------------------------------------
# The password
# ---------------------------------------------------------------------------
# Generated once and kept. A redeploy clears ~/project (engine#173) and leaves
# ~/.panelalpha alone, so regenerating here would hand the customer a new
# password every deploy while the one they wrote down stopped working.
#
# apr1 rather than bcrypt because the account image has openssl and no
# htpasswd, and apr1 is the format nginx's auth_basic has always read. tr drops
# the base64 characters that are painful to retype.
PW_STORE="${DATA_HOME}/admin-password"
if [ ! -f "${PW_STORE}" ]; then
    ( umask 077; openssl rand -base64 18 | tr -d '/+=' > "${PW_STORE}" )
    say "generated an admin password"
fi
chmod 600 "${PW_STORE}"

if [ ! -f "${AUTH_DIR}/htpasswd" ]; then
    ( umask 077
      printf 'admin:%s\n' \
        "$(openssl passwd -apr1 -stdin < "${PW_STORE}")" > "${AUTH_DIR}/htpasswd" )
fi
chmod 600 "${AUTH_DIR}/htpasswd"

# Basic-auth user -> Hubleys groups. One line per user, nginx `map` syntax.
# `admin` is in `admins`, which is what ADMINS=group:admins in the compose
# override turns into the Settings -> Admin page.
if [ ! -f "${AUTH_DIR}/groups.map" ]; then
    ( umask 077; cat > "${AUTH_DIR}/groups.map" <<'MAPEOF'
# Which Hubleys groups each signed-in user belongs to.
#
#   <basic-auth username>   "group1,group2";
#
# Add a user by appending a line to htpasswd beside this file and a line here,
# then `docker compose restart auth` in ~/project -- nginx reads htpasswd on
# every request but this file only when it starts.
#
# `guest` is every visitor who has not signed in. Giving it a group would give
# the whole internet that group.
admin   "admins";
MAPEOF
    )
fi
chmod 600 "${AUTH_DIR}/groups.map"

# ---------------------------------------------------------------------------
# The dashboard
# ---------------------------------------------------------------------------
# Written once and never rewritten: after the first deploy this file is the
# customer's dashboard, and a redeploy that "restored the default" would be
# indistinguishable from deleting their work.
#
# Not upstream's default.yml. That one is a 9 KB tour of every option --
# forty tiles pointing at other people's marketing pages, `allow: true` on all
# of them -- which is a fine reference and a bad homepage. This one is small,
# and it is built so that the access rules are visible in what the site does:
# a signed-out visitor sees the public section and a sign-in tile, a signed-in
# admin sees a section the visitor's copy of the page does not contain at all
# (getUserSections() in src/lib/server/authz.ts filters server-side, so the
# tiles are absent from the payload rather than hidden in CSS).
if [ ! -f "${CONFIG}" ]; then
    umask 077
    cat > "${CONFIG}" <<'CONFIGEOF'
# Hubleys configuration -- this file is your dashboard.
#
# PanelAlpha wrote it on the first deploy and will not touch it again: edit it
# and the page changes. A redeploy keeps it. It lives in
# ~/.panelalpha/hubleys/data/config.yml and is also reachable as config.yml in
# your project directory. After an edit, either restart the app or use
# Settings -> Admin -> Reload application.
#
# Every option: https://github.com/knrdl/hubleys-dashboard
# The full annotated example: src/lib/server/sysconfig/default.yml in the repo.
#
# WHO SEES WHAT
# Every entry takes `allow:` and `deny:`. `allow: true` means everyone,
# including anonymous visitors -- this dashboard is on a public domain.
# A visitor who has not signed in is the user `guest`. Signing in at
# /panelalpha-login as `admin` makes you `user:admin` in `group:admins`.
# Passwords live in ~/.panelalpha/hubleys/auth/htpasswd, groups in groups.map
# beside it.

search_engines:
  - title: Dashboard
    filter_dashboard: true   # filters the tiles below instead of searching the web
    allow: true
  - title: DuckDuckGo
    search_url: https://duckduckgo.com/
    autocomplete_url: https://duckduckgo.com/ac/
    allow: true

messages:
  - html: |
      <h1 style="font-size: 18pt">Welcome</h1>
      <p>This is a Hubleys dashboard, hosted on PanelAlpha.</p>
      <p>You are not signed in, so you are seeing the public tiles only.</p>
    allow: [user:guest]
  - html: |
      <h1 style="font-size: 18pt">Signed in</h1>
      <p>You are an administrator. Edit
      <code>~/.panelalpha/hubleys/data/config.yml</code> to change this page.</p>
    allow: [group:admins]

sections:
  - title: Public
    allow: true
    tiles:
      - title: Sign in
        subtitle: Administrators
        emoji: 🔑
        url:
          value: /panelalpha-login
          target: same-tab
        allow: [user:guest]     # pointless once you are signed in

      - title: Hubleys
        subtitle: Documentation
        emoji: 📖
        url: https://github.com/knrdl/hubleys-dashboard
        allow: true

      - title: PanelAlpha
        subtitle: The panel this is hosted on
        emoji: 🖥
        url: https://panelalpha.com/
        allow: true

  # Nothing in this section reaches a signed-out visitor: the section's own
  # rule fails first, so getUserSections() drops it whole.
  - title: Private
    allow: [group:admins]
    tiles:
      - title: Settings
        subtitle: Background, clock, weather
        emoji: ⚙️
        url:
          value: /settings/background
          target: same-tab
      - title: Clock
        emoji: ⏱
        url:
          value: /clock/
          target: same-tab
      - title: Sverdle
        subtitle: The demo game that ships with SvelteKit
        emoji: 🟩
        url:
          value: /sverdle/
          target: same-tab
CONFIGEOF
    say "wrote the starter dashboard to ~/.panelalpha/hubleys/data/config.yml"
else
    say "kept the dashboard already in ~/.panelalpha/hubleys/data/config.yml"
fi
chmod 600 "${CONFIG}"

# Where upstream's documentation says the file is. ~/project is re-cloned every
# deploy, so this is recreated every deploy; editing it edits the real file.
ln -sfn "${CONFIG}" config.yml

# ---------------------------------------------------------------------------
# .dockerignore
# ---------------------------------------------------------------------------
# So a redeploy does not redo `npm install && npm run build` for nothing.
#
# `COPY . /app/` is the first line of the build stage, so anything in the
# checkout that differs between two deploys throws away the 42s `npm install &&
# check && lint && build` layer under it. Two things do:
#
#   * .git. Two clones of the same commit are identical except for it -- the
#     index, the reflogs and the pack names differ every time. Nothing in the
#     build reads git history; PUBLIC_VERSION is a build ARG.
#   * The files the platform and this recipe put in ~/project beside the
#     application. The engine's generated docker-compose.yml carries
#     `PA_DEPLOY_PHASE: install` on the first deploy and `upgrade` on every one
#     after it -- measured: that one word was enough to make the second deploy
#     rebuild everything (cache_hit_ratio 0.313, 48.5s of build). None of these
#     files belong in the image either.
#
# Appended once, as a block, and guarded by its own marker so a redeploy does
# not keep growing the file.
if ! grep -q '^# >>> PanelAlpha' .dockerignore 2>/dev/null; then
    cat >> .dockerignore <<'IGNOREEOF'

# >>> PanelAlpha: keep `COPY . /app/` cacheable across redeploys, and keep the
# platform's own files out of the image.
.git
docker-compose.yml
docker-compose.override.yml
panelalpha/
config.yml
IGNOREEOF
    say "excluded .git and the platform's files from the build context"
fi

# ---------------------------------------------------------------------------
# The compose override
# ---------------------------------------------------------------------------
# Written here rather than shipped as overrides/docker-compose.override.yml
# because one value in it cannot be known until the account exists: the uid.
#
# The app image runs as uid 1000 (`USER 1000` in the Dockerfile) and the
# account is not uid 1000 -- it was 1002 on the host this was written on. A
# bind mount is owned by the account, so the stock image cannot write to it.
# Run without this line, against the same bind mount, the image says exactly
# that and exits:
#
#     Missing write permission for folder "/data/users/backgrounds".
#     The folder must be writable by the user with uid=1000.
#
# (src/hooks.server.ts, onServerStartup -> ensureDirExists -> process.exit(1),
# i.e. a restart loop, not a running site.) Running the app as the account
# instead keeps every file under /data owned by the customer, which is what
# makes config.yml editable over SFTP at all.
#
# Written after the app config's own overrides/ would have been laid down and
# before the engine generates docker-compose.yml, which is the order
# AppConfigBootstrap::run() uses, so nothing overwrites this afterwards.
APP_UID="$(id -u)"
APP_GID="$(id -g)"
cat > docker-compose.override.yml <<OVERRIDEEOF
# Written by PanelAlpha's Hubleys recipe (hooks/prepare.sh). Regenerated on
# every deploy -- edits here do not survive one; put lasting changes in a file
# of your own and name it in COMPOSE_FILE, or edit the recipe.
#
# Layered over the compose file the engine generates for a Dockerfile project,
# which already has the build context, the port, the restart policy, env_file
# and the public-URL variables right.
services:
  app:
    # The app is no longer what the account's webserver talks to; \`auth\` is.
    # Without this reset the generated '3000:3000' would still be published and
    # would take the port away from the proxy -- and would leave the
    # unauthenticated app directly reachable, which is the thing being fixed.
    ports: !reset []

    # Not uid 1000. See above.
    user: "${APP_UID}:${APP_GID}"

    # Replaces the engine's named volume for the same target. /data is where
    # config.yml, users/config/*.json and uploaded backgrounds live; a named
    # volume survives a redeploy but the customer cannot reach into it, and
    # anything inside ~/project is deleted by the next clone (engine#173).
    # Relative paths resolve against --project-directory, which is ~/project.
    volumes:
      - ../.panelalpha/hubleys/data:/data

    environment:
      # Explicitly the multi-user shape. SINGLE_USER_MODE=1 would make every
      # visitor the same administrator and switch off the access rules that
      # are the point of this app.
      SINGLE_USER_MODE: "false"
      # Who is an administrator: whoever \`auth\` puts in the admins group,
      # which is ~/.panelalpha/hubleys/auth/groups.map.
      ADMINS: "group:admins"

      # Where adapter-node gets the origin it checks form POSTs against.
      # Honest note: this fixes nothing that was observed. The handler falls
      # back to `... || 'https'` (handler.js:1438) and to the Host header, and
      # the engine's proxy chain preserves both, so the Settings POST answered
      # 200 with these unset as well -- measured, twice. They are set because
      # the fallback is a guess that happens to be right on this platform and
      # these are the answer: a domain served over plain http, or any proxy
      # that rewrites Host, breaks the guess and every Save becomes a 403.
      # Reading the headers also keeps it right when the customer adds a
      # domain, which pinning ORIGIN would not.
      PROTOCOL_HEADER: x-forwarded-proto
      HOST_HEADER: x-forwarded-host

      # adapter-node caps a request body at 512 kB, which is 20x smaller than
      # the background image upload the app advertises
      # (BACKGROUND_IMG_MAX_UPLOAD_MB=10). Measured on this deploy: a 1.47 MB
      # PNG answered `{"type":"error","error":{"message":"Payload Too
      # Large"}}` at handler.js:1405 with this unset, and 200 with it set.
      BODY_SIZE_LIMIT: "12M"

      # Neutralised on purpose. onServerStartup() copies FAVICON_FILE over
      # /app/client/favicon.png, and /app is owned by uid 1000 inside the
      # image while this container runs as the account -- so a customer who
      # dropped a favicon.png into their data directory would turn the next
      # start into an unhandled rejection under
      # NODE_OPTIONS=--unhandled-rejections=strict, i.e. a crash loop. A custom
      # favicon is the price; a dashboard that starts is worth more.
      FAVICON_FILE: /nonexistent/hubleys-favicon.png

  # The forward-auth proxy Hubleys' README tells you to put in front of it,
  # and the only thing on the account's port 3000. Its configuration, and what
  # each line is for, is ~/project/panelalpha/hubleys-auth.conf.
  auth:
    # The unprivileged build, run as the account itself. That is what lets the
    # password file stay 0600 in a 0700 directory the customer owns: stock
    # nginx drops its workers to uid 101 and would answer 500 on every
    # authenticated request because it cannot read the file (measured). Group 0
    # because this image makes /etc/nginx and /var/cache/nginx group-writable
    # for gid 0 precisely so it can run as an arbitrary uid.
    image: nginxinc/nginx-unprivileged:1.29-alpine
    user: "${APP_UID}:0"
    restart: unless-stopped
    depends_on:
      - app
    ports:
      - '3000:8080'
    volumes:
      - ./panelalpha/hubleys-auth.conf:/etc/nginx/conf.d/default.conf:ro
      - ./panelalpha:/etc/nginx/hubleys-static:ro
      # The directory, not the files in it: a single-file bind mount is bound
      # by inode, so an editor that writes a new file leaves the container
      # looking at the old one forever.
      - ../.panelalpha/hubleys/auth:/etc/nginx/hubleys-auth:ro
    mem_limit: 48m
OVERRIDEEOF
say "wrote docker-compose.override.yml (app as ${APP_UID}:${APP_GID}, auth proxy on :3000)"

# ---------------------------------------------------------------------------
# The page the customer reads next
# ---------------------------------------------------------------------------
if [ ! -f "${DATA_HOME}/README.panelalpha.md" ]; then
    ( umask 077; cat > "${DATA_HOME}/README.panelalpha.md" <<'MDEOF'
# Hubleys on PanelAlpha

## Signing in

    https://<your domain>/panelalpha-login

username  `admin`
password  in the file `admin-password` beside this one

That is HTTP Basic auth, served by the `auth` container in front of the app.
Your browser remembers it for the whole site; there is no sign-out button --
close the browser, or use a private window.

## Who sees what

Anyone who reaches your domain without signing in is the user `guest` and sees
only the entries in `data/config.yml` whose `allow:` rule includes them. The
starter config gives `guest` a "Public" section and a sign-in tile, and keeps
the "Private" section for `group:admins`. Nothing filtered out is sent to the
browser at all -- the filtering happens on the server, in `getUserSections()`.

If you want the whole dashboard private, delete every `allow: true` and every
`user:guest` rule from `config.yml`. A signed-out visitor then gets an empty
page rather than a password prompt, which is a deliberate trade: `/` stays a
200 so the panel's own health check keeps working.

## Adding a user

    cd ~/.panelalpha/hubleys/auth
    # one line: username:hash -- openssl passwd -apr1 prints the hash
    printf 'alice:%s\n' "$(openssl passwd -apr1)" >> htpasswd
    # optional: put them in groups, nginx map syntax
    echo 'alice   "family";' >> groups.map
    cd ~/project && docker compose restart auth

New passwords take effect at once. A change to `groups.map` needs the restart,
because nginx reads it when it starts.

## What lives where

    ~/.panelalpha/hubleys/
      admin-password            the generated password, plain text, 0600
      README.panelalpha.md      this file
      auth/htpasswd             usernames and password hashes
      auth/groups.map           username -> Hubleys groups
      data/config.yml           your dashboard. also ~/project/config.yml
      data/users/config/*.json  each user's own settings (background, clock)
      data/users/backgrounds/   wallpapers people upload
      data/logos/               your own tile icons, referenced by filename
      data/wallpaper/           drop photos here to get the "wallpaper
                                collection" background option

`~/project` is deleted and re-cloned on every deploy. Nothing you want to keep
belongs in it.

## Things worth knowing

  * Hubleys does not check passwords and never did. It reads `Remote-User` out
    of the request and believes it. The `auth` container overwrites that header
    on every request, which is what stops a visitor from simply sending
    `Remote-User: admin`. If you replace `panelalpha/hubleys-auth.conf`, keep
    the four `proxy_set_header Remote-*` lines.
  * Editing `config.yml` does not reload the app by itself. Settings -> Admin
    -> Reload application does, and so does `docker compose restart app`.
  * The weather widget and Unsplash backgrounds need API keys. Add
    `OPENWEATHERMAP_API_KEY` / `UNSPLASH_API_KEY` to the `app` service in a
    compose file of your own; the generated override is rewritten every deploy.
  * There is no admin UI for `config.yml`, by design. The file is the product.
MDEOF
    )
fi
chmod 600 "${DATA_HOME}/README.panelalpha.md"

say "prepared; credentials in ${PW_STORE}, notes in ${DATA_HOME}/README.panelalpha.md"

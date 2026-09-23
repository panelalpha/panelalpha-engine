#!/bin/bash
set -e
cd ~/project

# Homepage is a start page rendered by one index.php out of one JSON file.
# Three things have to be settled before the first request, and all three are
# host-side work on the checkout, which is why this hook does all of it and
# the recipe carries no stage commands at all (engine#169 drops those; it
# cannot drop a hook).
#
# 1. config.json has to exist. src/.gitignore lists it, so a clone never has
#    one, and index.php reads it unconditionally:
#      file_get_contents("config.json") -> false
#      json_decode(false, true)         -> null
#      array_merge($default_config, null) -> uncaught TypeError
#    That fatal is the `serving-php_error` verdict. It answers HTTP 200
#    because the warning above it is printed into the body first and printing
#    a body sends the headers -- display_errors is on because the base image
#    loads no php.ini at all (engine#185).
#
# 2. It has to live outside the checkout. config.json and hp_assets/img/* are
#    both gitignored and both inside ~/project, which a redeploy clears and
#    re-clones (engine#173). Everything persistent goes under
#    ~/.panelalpha/homepage; the compose override bind-mounts it at /data and
#    mounts its img/ over the checkout's.
#
# 3. The document root's .htaccess has to say three things upstream's does
#    not -- see the block at the bottom.

DATA_HOME="${HOME}/.panelalpha/homepage"

if [ ! -f src/index.php ] || [ ! -f src/config.sample.json ]; then
    echo "[homepage] no src/index.php or src/config.sample.json -- this is not a homepage checkout" >&2
    exit 1
fi

# ~ itself is root-owned 0755, so a new directory cannot be created there;
# ~/.panelalpha is created with the account and belongs to it. Made before the
# mount so Docker never creates them as root. 0700 because config.json holds
# the account's Unsplash credential.
mkdir -p "${DATA_HOME}/img"
chmod 700 "${DATA_HOME}"

# --------------------------------------------------------------- config.json

# Written once per account and never rewritten: after the first deploy this
# file is the customer's, and a redeploy must not undo their edits. It is the
# only place their configuration exists.
#
# Not a copy of upstream's config.sample.json, for two reasons. Its links are
# someone else's (facebook, trello, a phpMyAdmin on :3306 that does not exist
# here), and it omits `new_tab` on three of its six items -- index.php reads
# $item['new_tab'] with no isset(), so on an image with display_errors on that
# is three `Undefined array key` warnings printed inside <main>. Every key
# index.php or main.js reads is spelled out here on every item:
# alt / icon / link / new_tab, plus the top-level five and the `protected`
# block hp_assets/lib/ajax_get_image.php reads the same unguarded way.
#
# `idle_timer` is deliberately absent: upstream's README says leaving it out
# disables the auto-hide, and a menu that vanishes 60 seconds after the page
# loads reads as a broken page on a start page nobody has configured yet.
# `show_menu_on_page_load` is true for the same reason -- with it false the
# first thing an operator sees is a clock on an empty background.
# `time_to_refresh_bg` is upstream's recommended 90000 for an Unsplash demo
# key (README, "Unsplash Background Images").
if [ ! -f "${DATA_HOME}/config.json" ]; then
    # In a subshell: the umask is for this file, and the image directory below
    # is mounted into the container and wants its own mode.
    (
    umask 077
    cat > "${DATA_HOME}/config.json" <<'EOF'
{
  "title": "Homepage",
  "unlock_pattern": "space",
  "clock_format": "H:i j/n/Y",
  "hover_color": "#999",
  "time_to_refresh_bg": 90000,
  "show_menu_on_page_load": true,
  "items": [
    {
      "alt": "This site",
      "icon": "home",
      "link": "{{cur}}",
      "new_tab": false
    },
    {
      "alt": "Homepage on GitHub",
      "icon": "github",
      "link": "https://github.com/tomershvueli/homepage",
      "new_tab": true
    },
    {
      "alt": "PanelAlpha",
      "icon": "cloud",
      "link": "https://panelalpha.com",
      "new_tab": true
    },
    {
      "alt": "Font Awesome 4 icon names",
      "icon": "book",
      "link": "https://fontawesome.com/v4/icons/",
      "new_tab": true
    },
    {
      "alt": "Wikipedia",
      "icon": "wikipedia-w",
      "link": "https://www.wikipedia.org",
      "new_tab": true
    },
    {
      "alt": "DuckDuckGo",
      "icon": "search",
      "link": "https://duckduckgo.com",
      "new_tab": true
    }
  ],
  "protected": {
    "custom_url": "",
    "custom_url_selector": "",
    "custom_url_headers": [],
    "unsplash_client_id": ""
  }
}
EOF
    )
fi
chmod 600 "${DATA_HOME}/config.json"

if [ ! -f "${DATA_HOME}/README.panelalpha.md" ]; then
    cat > "${DATA_HOME}/README.panelalpha.md" <<'EOF'
<!-- Written by PanelAlpha. -->

# Homepage data

Everything you configure lives here, and nowhere else. `~/project` is cleared
and re-cloned on every deploy, and `config.json` and `hp_assets/img/*` are both
in the repository's `.gitignore` -- so if they were left in the checkout, the
next deploy would delete them.

    config.json   the whole configuration. Edit it here. There is a symlink
                  at `~/project/src/config.json` so the application still finds
                  the file at the path upstream's README names, but it points
                  at `/data/config.json` -- the mount as the web server sees
                  it -- so it looks broken from outside the container. Opening
                  it over SFTP will not work; this file is the one.
    img/          images for `img:` items, and upstream's sayagata-400px.png,
                  which main.css tiles as the page background. Mounted over
                  `~/project/src/hp_assets/img`, so a file dropped here is
                  reachable as `hp_assets/img/<name>`.

`config.json` is 0600 in a 0700 directory because `protected.unsplash_client_id`
and `protected.custom_url_headers` are credentials. Keep it that way; the web
server reads it as this account.

Every key is documented in the repository's README. Two worth knowing:

  * `{{cur}}` in a link is replaced with this site's own https:// URL.
  * `icon` is a Font Awesome **4.7** name without the `fa-` prefix
    (https://fontawesome.com/v4/icons/). Use `img` instead to point at a file
    in `img/`. An item needs one or the other -- and give every item all four
    of `alt`, `link`, `new_tab` and one of `icon`/`img`, because index.php
    reads all of them without checking whether they are there.

Homepage has no login, no sessions and no admin page. Whoever can reach this
site's URL can read every link on it.
EOF
fi

# index.php does file_get_contents("config.json") relative to its own
# directory and there is no way to point it elsewhere, so the file stays where
# it always was and the path is what moves. Absolute, at the mount point:
# resolved inside the container, which is the only place it is read. On the
# host it is a dangling link, which is why README.panelalpha.md above says to
# edit ~/.panelalpha/homepage/config.json rather than this.
ln -sfn /data/config.json src/config.json

# ------------------------------------------------------------------- images

# main.css:2 tiles hp_assets/img/sayagata-400px.png as the page background,
# and it is the one member of that directory the repository tracks. The
# compose override mounts ~/.panelalpha/homepage/img over the directory, so
# without this copy the background 404s on the first deploy. Copied only when
# absent: after that the directory is the account's.
if [ ! -e "${DATA_HOME}/img/sayagata-400px.png" ] && [ -f src/hp_assets/img/sayagata-400px.png ]; then
    cp src/hp_assets/img/sayagata-400px.png "${DATA_HOME}/img/sayagata-400px.png"
fi
chmod 700 "${DATA_HOME}/img"
chmod 644 "${DATA_HOME}/img"/* 2>/dev/null || true

# ---------------------------------------------------------------- .htaccess

# Appended rather than replaced: upstream's src/.htaccess is the Cache-Control
# and mod_deflate configuration of a page that is mostly static assets, and
# none of that is wrong here. Apache honours it -- the generated vhost grants
# AllowOverride All over ${PA_DOCROOT}, which is /app/src.
if ! grep -q 'Written by PanelAlpha' src/.htaccess 2>/dev/null; then
    cat >> src/.htaccess <<'EOF'

##############################################################################
# Written by PanelAlpha.
##############################################################################

# display_errors first, because on this image it is not cosmetic. The shared
# PHP base image loads no php.ini at all -- `php -i` reports
# `Loaded Configuration File => (none)` -- so display_errors is on and
# error_reporting is E_ALL, and every warning PHP raises is written into the
# response body. Homepage's index.php reads five array keys per item and a
# whole file with no guard on any of them, so a configuration that is merely
# incomplete publishes warnings in the middle of the page; a missing
# config.json publishes a stack trace under HTTP 200, because printing the
# warning sends the headers before the fatal is reached. With this, the same
# failure is a clean empty 500 and the detail goes to the container's log.
#
# <IfModule> because these directives only exist under mod_php, which is what
# this image runs (`apache2ctl -M` lists php_module, and mod_php.c is the name
# <IfModule> matches it by); a server without it skips them rather than
# refusing to start.
<IfModule mod_php.c>
  php_flag display_errors off
  php_flag log_errors on
</IfModule>

# config.json holds `protected.unsplash_client_id` and any headers a custom
# background URL needs, so it is the one file here that must never be served.
# Upstream denies it a few lines above this, in Apache 2.2 syntax
# (`Order allow,deny`), which works on this image only because
# mod_access_compat is loaded -- verified, but not something to depend on. The
# 2.4 spelling is stated here as well, and extended to config.sample.json:
# upstream serves that one, and it is both the file an operator is told to
# copy their configuration from and the documentation of the `protected` key
# names.
<FilesMatch "^config(\.sample)?\.json$">
  Require all denied
</FilesMatch>

# get_current_url() (index.php:16) builds the {{cur}} substitution from
# $_SERVER['HTTPS'] or SERVER_PORT == 443. Apache here listens on plain 8000
# behind the engine's TLS-terminating proxy, so neither is true of the request
# Apache sees, and every {{cur}} link would be http:// on an https page.
#
# It happens to come out right today without this line, for a reason no
# recipe should rest on: with no php.ini loaded, variables_order keeps its
# compiled default of EGPCS, which merges the process environment into
# $_SERVER, and the generated compose file sets HTTPS=on there. A php.ini
# appearing on this image -- php.ini-production spells variables_order
# "GPCS" -- would turn every {{cur}} link http:// again with nothing in the
# logs. This takes the scheme from the header the proxy actually sends
# (templates/dind/virtualHost-nginx-proxy.blade.php:39 sets X-Forwarded-Proto), which
# is the thing that is true.
<IfModule mod_setenvif.c>
  SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on
</IfModule>
EOF
fi

echo "[homepage] prepared: configuration at ${DATA_HOME}/config.json"

#!/bin/bash
# Runs on the account after the clone, before detection.
#
# One thing a Mafl checkout cannot supply: the file that is the entire
# application.
#
# nuxt.config.ts puts nitro's `data` storage on the fs driver at ./data, and
# loadConfig() in src/server/utils/config.ts throws `Config not found` when
# config.yml is not in it. src/pages/index.vue rethrows that as a rendering
# error, so every page is HTTP 500 until the file exists. Upstream's README
# tells the operator to write it before the first `docker compose up`; here
# there is no operator, only a Git URL, so it is written below.
#
# And it is written outside the checkout. A redeploy clears and re-clones
# ~/project (engine#173) -- and unlike a database or an upload directory, this
# file is the only thing the customer ever authors, so losing it loses the
# whole product. ~ is root-owned 0755 and the account can create nothing in it;
# ~/.panelalpha is created with the account and belongs to it.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/mafl"
CONFIG="${DATA_HOME}/data/config.yml"

say() { echo "[panelalpha] mafl: $*"; }

mkdir -p "${DATA_HOME}/data"
chmod 700 "${DATA_HOME}"

# ---------------------------------------------------------------------------
# The homepage
# ---------------------------------------------------------------------------
# Written once and never rewritten: after the first deploy this file is the
# customer's homepage, and a redeploy that "restored the default" would be
# indistinguishable from deleting their work.
#
# Four real services in one group rather than an empty skeleton. `services:` is
# the one required key in the schema (src/server/validations/config.ts) and an
# empty one renders a page with nothing on it -- which passes an HTTP check and
# is still not the product. Every link here goes somewhere useful for the next
# thing the customer will do, which is edit this file.
#
# 0600 in a 0700 directory. A service entry may carry `secrets:` (the
# OpenWeatherMap widget takes an API key), and the generated compose file in
# ~/project beside it is 0644 and readable by every other tenant on the host
# (engine#173).
if [ ! -f "${CONFIG}" ]; then
    umask 077
    cat > "${CONFIG}" <<'MAFLCONFIG'
# Mafl configuration -- this file is your homepage.
#
# PanelAlpha wrote it on the first deploy and will not touch it again: edit it
# and the page changes. A redeploy keeps it. It lives in
# ~/.panelalpha/mafl/data/config.yml and is also reachable as config.yml in
# your project directory.
#
# Every key, widget and theme: https://mafl.hywax.space/reference/configuration.html
title: My Home Page

services:
  Getting started:
    - title: Edit this page
      description: Everything you see comes from config.yml
      link: https://mafl.hywax.space/reference/configuration.html
      icon:
        name: mdi:file-document-edit-outline
        wrap: true
        color: '#609966'

    - title: Mafl documentation
      description: Services, tags, themes and widgets
      link: https://mafl.hywax.space/
      icon:
        name: mdi:book-open-variant
        wrap: true
        color: '#40513b'

    - title: Icon gallery
      description: Any name from here works as an icon
      link: https://icones.js.org/
      icon:
        name: mdi:emoticon-outline
        wrap: true
        color: '#9dc08b'

    - title: PanelAlpha
      description: The panel this homepage is hosted on
      link: https://panelalpha.com/
      icon:
        name: mdi:server
        wrap: true
        color: '#3b82f6'
MAFLCONFIG
    say "wrote the starter homepage to ~/.panelalpha/mafl/data/config.yml"
else
    say "kept the homepage already in ~/.panelalpha/mafl/data/config.yml"
fi
chmod 600 "${CONFIG}"

# ---------------------------------------------------------------------------
# .dockerignore
# ---------------------------------------------------------------------------
# So that a redeploy does not rebuild the whole Nuxt bundle for nothing.
#
# Upstream's .dockerignore is node_modules, .output, .nuxt and README.md -- it
# does not exclude .git, and the build context is the checkout. Two clones of
# the same commit are byte-identical except for .git (measured: `diff -r
# --exclude=.git` on two fresh clones is empty, while the index, the reflogs
# and the pack names differ every time), so `COPY . /app` hashes differently on
# every deploy and invalidates the `RUN yarn run build` layer under it. That is
# eight minutes of vite, per redeploy, to produce the bundle already in the
# cache. Nothing in the build reads git history -- changelogen is in the
# `release` script, which the image build never runs.
if ! grep -qx '\.git' .dockerignore 2>/dev/null; then
    printf '\n# Added by PanelAlpha: keeps `COPY . /app` cacheable across redeploys.\n.git\n' >> .dockerignore
    say "excluded .git from the build context so a redeploy can reuse the build cache"
fi

# Where upstream's documentation says to look. The clone wipes ~/project every
# deploy, so this is recreated every deploy; editing it edits the real file.
ln -sfn "${CONFIG}" config.yml

say "prepared; the homepage lives in ${DATA_HOME}/data"

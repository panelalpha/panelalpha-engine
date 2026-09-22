#!/bin/sh
# tine's frontend build, run by the engine's host Node pass.
#
# Where this runs: in the Node build container, `sh -c`, cwd /app, with
# ~/project bind-mounted at /app and the account's host cache at
# /var/cache/pa-js. `npm run build` in ../package.json is what calls it.
#
# Why it has to run at all. tine ships no compiled client: .gitignore excludes
# `tine20/Tinebase/js/build/*`, `tine20/Tinebase/styles/build/*` and
# `tine20/*/*/*FAT*`, so a checkout has the sources and none of the bundles.
# Tinebase_Core::detectBuildType() (tine20/Tinebase/Core.php:713-719, via
# Tinebase_Frontend_Http_SinglePageApplication::getAbsoluteAssetsJsonFilename())
# decides what tine *is* by whether `Tinebase/js/webpack-assets-FAT.json`
# exists: present, the installation is RELEASE and serves the bundles; absent,
# it is DEVELOPMENT and getAssetsMap() fetches the asset manifest over HTTP
# from a webpack-dev-server on localhost:10443 and throws when there is none.
# There is no third mode. So either this build runs or the application cannot
# render a single page.
#
# Node 24 and not upstream's 26: `engines.node` in ../package.json asks for
# >=24 and NodeRuntime::resolveMajor() answers with the newest major the engine
# seeds (18/20/22/24). The root `.nvmrc` says 10.15.3 -- a version that cannot
# parse an .mjs webpack config -- and engines.node is read first
# (NodeRuntime::declaredVersion()), which is the only reason this works.
set -e

say() { echo "[tine] $*" >&2; }

cd /app/tine20/Tinebase/js

# npm-shrinkwrap.json pins the tree, so this is `npm ci` in spirit; `install`
# rather than `ci` because ci deletes node_modules first and a redeploy has
# already lost it with the checkout, so there is nothing to save and the
# difference is only which one tolerates a drifted lockfile.
#
# --ignore-scripts: nothing in this tree has a postinstall that produces
# anything the build reads (upstream's own jsdependency image passes the same
# flag), and it is ~90 packages of arbitrary install hooks running on the host
# daemon otherwise.
#
# Ten dependencies resolve to `git+ssh://git@github.com/...` in
# npm-shrinkwrap.json -- html2canvas, html5-file-selector, jspdf, keydown,
# postal.federation, postal.request-response, postal.xwindow,
# storage-based-queue, unminified-webpack-plugin -- and npm fetches those by
# shelling out to git. That is why ../package.json declares a script that
# invokes git: NodeRuntime::needsGitBinary() reading a package.json script is
# the only lever a project has over the image variant, and without it the pass
# runs in node:24-bookworm-slim, which has no git binary and fails the install
# with `npm error syscall spawn git` / `npm error git dep preparation failed`.
# Measured; it cost one deploy.
say "installing frontend dependencies"
npm install --no-audit --no-fund --omit=optional --ignore-scripts

# webpack/panelalpha.mjs, this recipe's own, copied in beside upstream's:
# common.mjs unchanged, prod.mjs's source maps, unminified second bundle set
# and brotli pass dropped. The reason is in that file's header -- prod.mjs as
# written is OOM-killed in the engine's build container, measured. Its
# output.path is common.mjs's `baseDir` = tine20/, so every bundle lands in the
# tree the container serves and nothing has to be moved afterwards.
#
# BUILD_DATE is read by common.mjs's DefinePlugin and shown in the client's
# About dialog; unset it is the string "undefined" on screen.
#
# No --progress: it redraws a bar into the deploy log thousands of times.
say "building the client (webpack, production)"
BUILD_DATE="$(date -u '+%F %T')"
export BUILD_DATE
node ./node_modules/webpack/bin/webpack.js --config webpack/panelalpha.mjs

# The one artefact the whole installation is judged by. A webpack that fails
# halfway can still exit 0 on a warning, and the deploy would then finish,
# report success, and serve an application that throws
# `Tinebase_Exception_NotFound: assets json not found` on every request.
if [ ! -f /app/tine20/Tinebase/js/webpack-assets-FAT.json ]; then
    say "the build finished but Tinebase/js/webpack-assets-FAT.json is missing;"
    say "tine would come up in DEVELOPMENT mode and fail on every page"
    exit 1
fi

# node_modules has done its job and nothing at runtime reads it: the one thing
# outside the bundles that comes from it is bootstrap's CSS, which
# copy-webpack-plugin has already written to Tinebase/styles/build/bootstrap/.
# Leaving it costs the account 567 MB of quota (measured) inside the document
# root, where every .js in it is web-readable -- the .htaccess rewrite covers
# .php files, not JavaScript. A redeploy re-clones ~/project and would lose it
# regardless; with the host npm cache warm the reinstall is the same cost
# either way.
say "removing node_modules from the document root"
rm -rf /app/tine20/Tinebase/js/node_modules

say "client built"

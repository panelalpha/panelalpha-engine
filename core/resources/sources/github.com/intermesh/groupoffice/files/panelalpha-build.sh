#!/bin/bash
# The frontend build, run by the engine's host Node pass.
#
# Reached as `npm run build` from the package.json this recipe writes at the
# checkout root: HostCompile::runForPhp() reads package.json from $projectDir
# with no app_root applied (HostCompile.php:188-190), and Group Office's own
# www/package.json declares npm workspaces and no build script. So the root
# package.json exists only to point here.
#
# This runs on the host, in a node:22 container with ~/project bind-mounted at
# /app and the account's npm cache at /var/cache/pa-js/npm. It is not in the
# runtime container and never will be: the PHP base image has no Node.
#
# Nothing here is this recipe's idea of how to build Group Office. It is
# upstream's scripts/install-npm.sh, which is what scripts/build.sh calls.
set -euo pipefail

say() { echo "[groupoffice] $*"; }

# .gitignore excludes /**/*.css, so this is the file whose absence is the whole
# reason for the build: views/Layout.php:16 calls filemtime() on it for every
# page Group Office renders.
THEME_CSS="www/views/Extjs3/themes/Paper/style.css"

# ---------------------------------------------------------------- submodules --
#
# The build compiles GOUI out of www/views/goui/goui and
# www/views/goui/groupoffice-core, and www/package.json lists both as npm
# workspaces -- so `npm ci` fails outright if they are empty rather than
# building something subtly wrong. The engine fetches submodules after the
# clone (app/System/Project/Git.php:117-123) and logs a warning rather than
# failing when one cannot be had, so the check belongs here, where it can say
# which one and why it matters.
for m in www/views/goui/goui www/views/goui/groupoffice-core; do
    if [ ! -f "$m/package.json" ]; then
        say "submodule $m is empty." >&2
        say "Group Office keeps its web client in separate repositories and the" >&2
        say "frontend cannot be built without them. Check the deploy log for the" >&2
        say "submodule fetch." >&2
        exit 1
    fi
done

say "building the frontend with upstream's scripts/install-npm.sh"
bash scripts/install-npm.sh nodev

if [ ! -f "$THEME_CSS" ]; then
    say "the build reported success but $THEME_CSS is missing" >&2
    exit 1
fi
# The workspace build writes each package's bundle under its own name:
# dist/goui/script/index.js is the shared widget library and
# dist/groupoffice-core/script/index.js is Group Office's own client code.
# Both are loaded by Extjs3::loadGoui().
for b in goui groupoffice-core; do
    if [ ! -f "www/views/goui/dist/$b/script/index.js" ]; then
        say "the build reported success but www/views/goui/dist/$b/script/index.js is missing" >&2
        exit 1
    fi
done

# ------------------------------------------------------------- node_modules --
#
# Build tooling only -- esbuild, sass, typescript and their trees. What the
# application serves is the compiled CSS and the dist/ bundles, which stay.
# Dropped because ~/project is the account's disk quota and this is ~200 MB of
# it per account; the next deploy re-installs from the npm cache mount, which
# is what makes that cheap rather than a re-download.
#
# The root node_modules is NOT removed: it is a bind mount of the account's
# shared cache directory (DindHostBuilder::nodeBuildArgv() mounts
# <cache>/node_modules at /app/node_modules), so emptying it would throw away
# the cache rather than a copy of it.
find www -type d -name node_modules -prune -exec rm -rf {} + 2>/dev/null || true

say "frontend built"

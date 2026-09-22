#!/bin/sh
# Build the React SPA and merge it into the Laravel document root.
#
# This is step 3 and step 4 of the repository's own build.sh, which cannot run
# here: build.sh starts by bringing up the workstation compose stack and
# re-execing itself inside it. The two steps it does that matter are the only
# two done here.
#
# Called as `npm run build` from ~/project by the engine's host Node build, so
# the working directory is the repository root and /app is the whole checkout.
set -e

root=$(pwd)

cd client

# `npm ci` cannot be used: the committed package-lock.json is out of sync with
# package.json upstream -- `npm ci` stops on `Missing: yaml@2.9.1 from lock
# file` and installs nothing. Resolving from package.json is the only way this
# tree installs at all.
npm install --no-audit --no-fund --loglevel=error

# The host build exports CI=true (DindHostBuilder::toolchainEnv), and
# react-scripts turns every eslint warning into an error when it sees that.
# This tree has three -- two `import/no-anonymous-default-export` in
# src/stores/ and a duplicate `componentDidUpdate` in src/views/Notes -- so
# with CI left alone the build fails on code that upstream ships and releases.
CI=false npm run build

cd "$root"

# build.sh's merge, same direction: the client bundle is copied over the
# server's public directory. The four files the server owns there -- api.php,
# setup.php, .htaccess and web.config -- are not in the client bundle, so
# nothing has to be restored afterwards; favicon.ico is in both and the
# client's wins, which is what build.sh does too.
cp -R client/build/. server/public/

# node_modules is ~450 MB of build input in the customer's home directory, and
# nothing serves from it. The bundle it produced is in server/public now.
rm -rf client/node_modules client/build

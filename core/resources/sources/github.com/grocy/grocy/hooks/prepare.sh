#!/bin/bash
set -e
cd ~/project

# Turn on the engine's frontend pass for a project that has no frontend build,
# and get it the git binary that pass needs.
#
# grocy's CSS and JavaScript are yarn dependencies: .yarnrc pins
# --modules-folder to public/packages, and views/layout/default.blade.php loads
# bootstrap, jquery, datatables, fontawesome and the rest from that path. So
# `yarn install` is not a step before the build, it *is* the build -- upstream's
# own installer is `composer install` then `yarn install` and nothing else
# (.devtools/install_dependencies.bat).
#
# Two scripts, and neither is grocy's:
#
#   build          HostCompile::runForPhp only runs the Node pass when
#                  package.json declares a `build` script, and grocy's
#                  package.json has no "scripts" key at all. Without one
#                  nothing ever ran yarn: the deploy succeeded, Apache served
#                  public/, and every page came back unstyled with no working
#                  JavaScript. This is the switch that turns the pass on.
#
#                  `test -d ... || yarn install` rather than `true`: the
#                  install itself is skipped when the engine's node_modules
#                  cache reports a lockfile hit, and that cache outlives the
#                  checkout ~/project is re-cloned into. On such a redeploy the
#                  assets are gone while the cache says they are not, and this
#                  line is what puts them back. Otherwise it is a no-op.
#
#   pa-needs-git   Never run by anything -- it is read, not executed.
#                  NodeRuntime::needsGitBinary() scans package.json's scripts
#                  for a literal git invocation and, on a hit, swaps the slim
#                  Node image for the full bookworm one, which is the only way
#                  the host compile can have git: it runs a stock image with no
#                  way to apt-get anything into it. grocy needs git because one
#                  of its dependencies is a repository rather than a registry
#                  release -- @danielfarrell/bootstrap-combobox resolves to
#                  https://github.com/berrnd/bootstrap-combobox.git#master-fork
#                  in yarn.lock, and yarn 1 shells out to git to fetch it.
#                  Without this the whole deploy dies on `error Couldn't find
#                  the binary git` after a successful composer install.
#
#                  needsGitBinary() does not look at dependency *URLs* today,
#                  only at scripts and a list of build plugins; if it learns to,
#                  delete this script.
#
# Guarded on "scripts": an upstream that grows a build of its own must keep it.
if ! grep -q '"scripts"' package.json; then
    sed -i '1s|^{$|{\n\t"scripts": {\n\t\t"build": "test -d public/packages/bootstrap \|\| yarn install",\n\t\t"pa-needs-git": "git ls-files"\n\t},|' package.json
fi

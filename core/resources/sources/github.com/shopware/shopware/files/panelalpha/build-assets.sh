#!/bin/bash
# Runs in the engine's host Node container (DindHostBuilder::nodeBuildArgv),
# invoked as `npm run build` at the root of the checkout by
# HostCompile::runForPhp(). The checkout is bind-mounted at /app and the
# account's uid owns it, but the cgroup is the *engine's* build budget and not
# the account's limit: DindEngine::resolveBuildMemory() takes
# DEPLOY_BUILD_MEMORY when an operator set one, and otherwise
# ServiceLimits::hostBuildMemoryMb(), which is max(2048, min(8192, hostRAM/3))
# MB. NODE_OPTIONS already carries --max-old-space-size=70% of that.
#
# Shopware's monorepo ships no compiled frontend. src/Storefront/Resources/
# .gitignore excludes `app/storefront/dist/*` (all but the static `assets`
# subdirectory) and `public/`, and src/Administration/Resources/.gitignore
# excludes its `public/` too, so a clone has neither the storefront bundle nor
# the administration SPA. Both are built here; nothing else in the deploy has
# a Node toolchain (the php manifest's build stage runs in a PHP image, and
# the runtime image is Apache + PHP).
set -u
cd "$(dirname "$0")/.."
ROOT="$PWD"
export PROJECT_ROOT="$ROOT"
export CI=true

# The cgroup the engine put this container in. Read rather than assumed: the
# two builds have very different appetites, the administration one cannot be
# made to fit under about 2.8 GB, and the budget is a property of the engine
# host -- 5202 MB on the 15 GB host this was measured on, but the 2048 MB
# floor on anything under ~6 GB. So this is the number that decides whether to
# attempt it.
limit_mb() {
    local v
    for f in /sys/fs/cgroup/memory.max /sys/fs/cgroup/memory/memory.limit_in_bytes; do
        [ -r "$f" ] || continue
        v=$(cat "$f" 2>/dev/null)
        case "$v" in ''|max|*[!0-9]*) continue ;; esac
        # A limit in the exabytes is cgroup's way of saying "none".
        [ "$v" -gt 0 ] && [ "$v" -lt 1000000000000 ] && { echo $((v / 1024 / 1024)); return; }
    done
    echo 0
}
MEM=$(limit_mb)
echo "[shopware] host asset build, memory limit ${MEM}MB"

# ---------------------------------------------------------------------------
# 1. The Storefront. Mandatory: without it the shop cannot be themed at all.
#
# Two separate things come out of this project and Shopware needs both before
# `theme:change` will run:
#
#   vendor/  -- copy-to-vendor.js copies node_modules/{bootstrap,tiny-slider,
#      flatpickr} into src/Storefront/Resources/app/storefront/vendor, and
#      theme.json resolves the theme's SCSS `vendor` alias to that directory.
#      Without it ThemeCompiler (scssphp, so this part is PHP) dies on
#      "`~vendor/bootstrap/scss/functions` file not found for @import:
#      .../src/scss/variables.scss on line 11".
#
#   dist/storefront/storefront.js -- theme.json lists it under `script`, and
#      ThemeFileResolver::processDirectFile() throws a ThemeCompileException
#      for a script file that is not on disk. That is thrown from
#      resolveScriptFiles(), i.e. before any CSS is written, so a missing
#      storefront bundle fails the theme assignment outright rather than
#      degrading it.
#
# Measured on this engine: npm ci 37s / 845MB of node_modules, the production
# build ~6s. Comfortable inside a 2GB account.
# ---------------------------------------------------------------------------
# 0. var/plugins.json, which the storefront's webpack config refuses to start
#    without and which only PHP can normally write.
#
# webpack.config.js:61-64 resolves $PROJECT_ROOT/var/plugins.json and *throws*
# when it is missing -- "The file /app/var/plugins.json could not be found.
# Try bin/console bundle:dump to create this file." bundle:dump is a Symfony
# console command and there is no PHP in this container, and there is no hook
# between the composer stage (which creates vendor/) and this one where it
# could be run.
#
# `{}` is the correct content here, not a stub that papers over something.
# Everything webpack reads that file for is under `pluginEntries`, which
# filters to definitions that have a `storefront.entryFilePath` *and* whose
# technicalName is not `storefront` -- i.e. third-party plugins only. The core
# storefront bundle comes from `coreConfig` in the same file. A freshly cloned
# Shopware has no plugins, so the set is empty either way; an account that
# later installs one runs bundle:dump and rebuilds.
#
# config_js_features.json is deliberately left alone: webpack treats it as
# optional (existsSync, line 38) and prints a red notice saying all feature
# flags are deactivated, which is both true and what a stock release build
# wants -- the flags it would switch on are unreleased-major toggles.
if [ ! -f var/plugins.json ]; then
    mkdir -p var
    printf '{}' > var/plugins.json
    echo "[shopware] wrote an empty var/plugins.json (no PHP in the Node stage; see the comment)"
fi

echo "[shopware] building the Storefront"
cd "$ROOT/src/Storefront/Resources/app/storefront" || exit 1
npm ci --no-audit --no-fund --prefer-offline || exit 1
node copy-to-vendor.js || exit 1
NODE_ENV=production npm run production || exit 1
cd "$ROOT"

# ---------------------------------------------------------------------------
# 2. The Administration SPA, when there is room for it.
#
# `npm run build` here is `VITE_MODE=production ts-node -T build.ts`, a Rollup
# pass over the whole administration plus a second one for the Storefront's
# admin modules. Measured on this engine, three runs of the same commit:
#
#   2000MB cgroup / 1400MB heap -> FATAL ERROR: Ineffective mark-compacts near
#                                  heap limit
#   2400MB cgroup / 1800MB heap -> exit 137, cgroup OOM kill
#   2800MB cgroup / 2200MB heap -> success in 52s, RSS peaked ~2.5GB
#
# (The heap in each row is what ServiceLimits::nodeHeapMbFor() derives from
# that cgroup, so those are the pairs a real deploy would see.)
#
# There is no flag that makes it smaller: the peak is Rollup's module graph,
# not minification. And 2048MB is exactly ServiceLimits::MIN_BUILD_MEMORY_MB,
# the floor an engine host under ~6GB of RAM lands on -- so on a small engine
# this build cannot run at all, whatever the account is sized at.
#
# Attempting it anyway spends 51s and 1.2GB of node_modules to arrive at a
# kill, and a failure here fails the whole deploy (runContainer() throws),
# taking a storefront that does work down with it. So it is skipped, loudly,
# rather than attempted and swallowed: the shop is still a working shop --
# storefront, customer account area and checkout are server-rendered Twig --
# but /admin will not load, and panelalpha/shopware-setup.sh says so again
# where an operator will see it.
ADMIN_MIN_MB=2700
ADMIN_DIR="$ROOT/src/Administration/Resources/app/administration"
if [ "$MEM" -ne 0 ] && [ "$MEM" -lt "$ADMIN_MIN_MB" ]; then
    echo "[shopware] SKIPPING the Administration build: this engine's host build budget is"
    echo "[shopware] ${MEM}MB and the Vite/Rollup pass needs about ${ADMIN_MIN_MB}MB. Raise"
    echo "[shopware] DEPLOY_BUILD_MEMORY, or deploy on a larger engine host. The storefront"
    echo "[shopware] is unaffected; /admin will not load until this build can run."
else
    echo "[shopware] building the Administration"
    cd "$ADMIN_DIR" || exit 1
    npm ci --no-audit --no-fund --prefer-offline || exit 1
    # Explicit rather than inherited: the engine's NODE_OPTIONS is 70% of the
    # limit, and this build wants everything that is not needed for Rollup's
    # own off-heap buffers.
    NODE_OPTIONS="--max-old-space-size=$(( MEM > 0 ? MEM - 600 : 2200 ))" \
        VITE_MODE=production npx ts-node -T build.ts || exit 1
    cd "$ROOT"
fi

echo "[shopware] host asset build finished"

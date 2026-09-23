#!/bin/bash
# Account shell, after the clone and after files/ has been installed, before
# detection and the build.
#
# Three things a file snippet cannot do: make the root composer.json say what
# wikimedia/composer-merge-plugin would have made it say (the whole reason
# this repository deploys to a fatal error), keep the account's generated
# secrets and its site data somewhere a redeploy does not delete, and tell a
# human what the administrator password is.
set -e
cd ~/project

say() { echo "[concrete] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL, and a fork or a mirror answers to the
# same one. Everything below assumes the concretecms/concretecms layout.
# Saying so here turns a confusing 500 into one line in the deploy log.
if ! grep -q '"name"[[:space:]]*:[[:space:]]*"concrete5/concrete5"' composer.json 2>/dev/null; then
    say "WARNING: composer.json is not concrete5/concrete5; this recipe may not fit"
fi
[ -f concrete/composer.json ] || say "WARNING: concrete/composer.json is missing"
[ -f index.php ] || say "WARNING: index.php is missing; there is no front controller to serve"

# ---------------------------------------------------------------------------
# 1. The merge the plugin is not allowed to do.
#
# This is the recipe. concretecms/concretecms splits its manifest in two: the
# root composer.json requires exactly one package -- the merge plugin -- and
# declares no `autoload` at all, while concrete/composer.json holds the 90
# real requirements and the `Concrete\Core\` => `src` mapping. The plugin
# merges the second into the first at runtime.
#
# The php platform installs with `--no-plugins` (PhpHostBuild::
# SAFE_INSTALL_FLAGS) and PhpHostBuild::mayRunPlugins() will not drop the flag
# for a lock pinning three plugins none of which is a known installer. The
# packages still install from the lock, but Composer's autoload dump walks the
# root package's own requirements -- and the root's requirements are one
# plugin. The result is an autoloader with nine classes in it and a fatal on
# every request.
#
# So the merge happens here instead, deterministically, with no plugin code
# executed anywhere. Two deliberate narrowings from what the plugin does:
#
#   * only requirements whose name contains a `/` are copied. `php`, `ext-*`
#     and `composer-runtime-api` are platform requirements: they contribute
#     nothing to an autoload map, and PhpRuntime::requirementFor() reads
#     composer.json's constraints when the lock's platform is contradicted --
#     so copying `"php": "^7.3||^8.0"` into the root could move the whole
#     project onto an older minor than the control deploy resolved. The lock
#     already carries every one of them under `platform`.
#
#   * `provide`, `bin`, `config` and the rest are not copied. The install
#     reads the lock, so nothing there can change which packages arrive.
#
# Relative autoload paths are rebased on `concrete/`, which is what the plugin
# does with an included file in a subdirectory.
#
# The one visible cost: `require` is part of Composer's lock content-hash, so
# the install prints "The lock file is not up to date with the latest changes
# in composer.json". It is a warning. `composer install` still installs the
# lock and nothing else -- "Nothing to install, update or remove" on a second
# pass -- and the alternative is running three plugins on the host daemon.
# Written with python3 rather than PHP: hooks/prepare.sh runs in the account's
# shell on the host, which has python3, openssl and jq in it and no `php` at
# all -- the interpreter only exists inside the container and inside the
# Composer image the host build uses.
if ! command -v python3 >/dev/null 2>&1; then
    say "ERROR: python3 is needed to merge concrete/composer.json into the root manifest"
    say "ERROR: without it every request answers with a PHP fatal; refusing to continue"
    exit 1
fi
python3 - <<'PYMERGE'
import json, sys

root_file, core_file = "composer.json", "concrete/composer.json"
try:
    root = json.load(open(root_file))
    core = json.load(open(core_file))
except Exception as e:                        # a fork that moved things
    sys.stderr.write("[concrete] WARNING: could not read a composer manifest (%s); skipping merge\n" % e)
    raise SystemExit(0)

added = 0
for name, constraint in (core.get("require") or {}).items():
    if "/" not in name:                       # platform requirement; see above
        continue
    if name not in root.setdefault("require", {}):
        root["require"][name] = constraint
        added += 1

def rebase(path):
    return "concrete/" + str(path).lstrip("./")

merged = {}
for kind, rules in (core.get("autoload") or {}).items():
    if kind in ("psr-4", "psr-0"):
        merged[kind] = {
            prefix: [rebase(p) for p in (paths if isinstance(paths, list) else [paths])]
            for prefix, paths in rules.items()
        }
    elif kind in ("classmap", "files", "exclude-from-classmap"):
        merged[kind] = [rebase(p) for p in rules]

if merged:
    autoload = root.setdefault("autoload", {})
    for kind, rules in merged.items():
        if isinstance(rules, dict):
            autoload.setdefault(kind, {}).update(rules)
        else:
            autoload[kind] = list(autoload.get(kind, [])) + rules

with open(root_file, "w") as f:
    json.dump(root, f, indent=2)
    f.write("\n")

sys.stderr.write("[concrete] merged concrete/composer.json into the root manifest: "
                 "%d requirements, autoload %s\n" % (added, ", ".join(merged) or "(none)"))
PYMERGE

# ---------------------------------------------------------------------------
# 2. The things that must outlive the checkout.
#
# GitRepository::cloneConfiguredRepository() empties ~/project before every
# deploy (engine#173) and the account's MySQL database survives it. Everything
# Concrete writes that is *data* -- the connection it was installed against,
# every setting changed from the dashboard, every uploaded file, every
# marketplace add-on -- is written inside ~/project, so all of it goes here
# instead and is bind-mounted back in by
# overrides/docker-compose.override.yml.
#
# ~/.panelalpha rather than $HOME itself, because the home is root-owned 0755
# and an account cannot write into it. 0700 on the subdirectory rather than
# the 0755 the umask would give: /home is world-traversable on this host, and
# application/config/database.php is the account's MySQL password in a file.
# Root (the Docker daemon, which resolves the bind mounts) and the account
# (which the container runs as) both still reach it.
STORE_DIR="$HOME/.panelalpha/concrete"
mkdir -p "$STORE_DIR"
chmod 700 "$STORE_DIR"
# Created here rather than by the daemon, because a bind mount whose source
# does not exist is created by the daemon as **root**, and the container --
# running as this account -- then cannot write into it. Concrete's installer
# checks both for writability by name and refuses to start without them.
mkdir -p "$STORE_DIR/config" "$STORE_DIR/files" "$STORE_DIR/packages"
chmod 755 "$STORE_DIR/config" "$STORE_DIR/files" "$STORE_DIR/packages"

STORE="$STORE_DIR/concrete.env"
if [ ! -f "$STORE" ]; then
    # The umask is inside a subshell on purpose: it has to cover the
    # redirection that creates the file, so the password is never briefly
    # world-readable, and it must not leak into the rest of this script.
    (
        umask 077
        cat > "$STORE" <<EOF
# Generated once by the PanelAlpha Concrete CMS recipe. Read into the
# container as a second env_file (see overrides/docker-compose.override.yml).
# Kept out of ~/project, which every deploy re-clones from scratch, and out of
# .env, which ProjectEnvironment::apply() copies to a world-readable
# .env.default (engine#173).
#
# The first administrator, created non-interactively from the install stage so
# that /install is never left open for whoever arrives first to claim. The
# username is not configurable: Concrete's installer always names it "admin".
PA_CONCRETE_ADMIN_EMAIL=admin@example.com
PA_CONCRETE_ADMIN_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
PA_CONCRETE_SITE_NAME=Concrete CMS
# Which starting point c5:install imports. atomik_blank is upstream's own
# non-interactive default and gives a themed, empty site. atomik_full adds
# demo pages and images; elemental_full is the older theme's. Changing this
# after the first deploy does nothing -- the install has already happened.
PA_CONCRETE_STARTING_POINT=atomik_blank
EOF
    )
    chmod 600 "$STORE"
    say "generated the account's administrator password in ~/.panelalpha/concrete/concrete.env"
fi

# Where a human is pointed. ~/project is re-cloned every deploy, so this is a
# copy of the stored value rather than the value itself -- the same password on
# every redeploy, and the one the database actually holds. The leading dot and
# the `panelalpha` in the name are both already denied by the generated vhost,
# and files/.htaccess denies the name a third time.
(
    umask 077
    {
        echo "# Written by PanelAlpha. Concrete's installer creates the first"
        echo "# administrator and there is no sign-up page; this is that account."
        echo "USERNAME=admin"
        grep -E '^PA_CONCRETE_ADMIN_(EMAIL|PASSWORD)=' "$STORE" | sed 's/^PA_CONCRETE_ADMIN_//'
        echo "# Sign in at <your domain>/login , dashboard at <your domain>/dashboard"
    } > .panelalpha-admin-password
)
chmod 600 .panelalpha-admin-password

say "prepared; site data lives in ~/.panelalpha/concrete and survives a redeploy"

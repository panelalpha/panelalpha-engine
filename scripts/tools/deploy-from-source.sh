#!/usr/bin/env bash
# Upload this working tree to a host and bring the engine up from it, in one
# command. Run it from your workstation, not from the engine host.
#
#   bash scripts/tools/deploy-from-source.sh root@engine.example.com
#   bash scripts/tools/deploy-from-source.sh root@host -- --no-sysbox --ip 1.2.3.4
#
# Everything after `--` is passed through to scripts/bootstrap-from-source.sh.
# Re-run it after any local edit: the upload is incremental and the bootstrap
# is idempotent, so the second run is a redeploy.

set -euo pipefail

ENGINE_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
REMOTE_DIR='/opt/panelalpha/shared-hosting'
DRY_RUN=0
TARGET=''
BOOTSTRAP_ARGS=()

while [ $# -gt 0 ]; do
    case "$1" in
    --remote-dir) REMOTE_DIR="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    --) shift; BOOTSTRAP_ARGS=("$@"); break ;;
    -h | --help) sed -n '2,11p' "$0"; exit 0 ;;
    *) [ -z "$TARGET" ] && TARGET="$1" && shift || { echo "Unexpected argument: $1" >&2; exit 1; } ;;
    esac
done

[ -n "$TARGET" ] || { echo "Usage: $0 [user@]host [--remote-dir DIR] [--dry-run] [-- bootstrap args]" >&2; exit 1; }

# Host state the installer/bootstrap generates. rsync protects excluded paths
# from --delete, so these survive every upload.
#
# The patterns are anchored to the transfer root, so `/`.env` protects only the
# .env at the top and *not* the one under core/ -- which is where Docker puts
# one. docker-compose.yml binds `./.env-core:/var/www/html/.env`, and Docker
# materialises that file bind by creating `core/.env` on the host if it is
# absent. It is absent from this worktree (core/.gitignore lists it), so it is a
# delete candidate on every sync, and --delete removes it. The bind then lands
# on nothing, the engine reads no APP_KEY, and every POST /api/projects answers
# 500 with MissingAppKeyException while the GETs keep working. Name it here, and
# name the backup the engine's own rotation writes beside it.
EXCLUDES=(
    .git .idea .aider-desk .aider.input.history .github .claude
    .env .env-core version crt
    # .pae-backup is the copy `pae configure` keeps of .env-core before each edit.
    core/.env core/.env.backup core/.env.pae-backup
    users logs webserver-logs webserver-config
    nginx-proxy-conf.d nginx-proxy-logs
    pureftpd data awstats-config awstats-data litespeed-config build
    scripts/monit.conf scripts/monit-script.sh
    docker-compose.yml-webserver
    # An operator's own Compose override: bootstrap and pae run bare `docker compose`
    # so that it applies.
    docker-compose.override.yml
    config/logrotate config/exim config/modsecurity config/sftp config/pure-ftpd
    # The same five names exist under core/ on an installed host -- a stale
    # duplicate of the directory above, which nothing mounts today (the sftp and
    # ftp containers bind `config/sftp` and `config/pure-ftpd`, one level up).
    # Excluded all the same: they are host state, not source, so a sync must not
    # decide they are deletions, and the day something does mount them they must
    # still be here. This is the same class of mistake as core/.env below.
    core/config/logrotate core/config/exim core/config/modsecurity
    core/config/sftp core/config/pure-ftpd
    core/vendor core/storage core/bootstrap/cache
    tests/api/node_modules tests/api/.playwright tests/api/test-results tests/api/playwright-report
    # The API suite's host config and token. Gitignored, so the uploading tree
    # never has it and --delete would remove it from the host on every sync.
    tests/api/env/.env tests/api/env/.env.*
    scripts/tools/dind-test/vendor
)

RSYNC_ARGS=(-az --delete --human-readable --info=stats1)
# The template is source, and would otherwise match tests/api/env/.env.* below.
RSYNC_ARGS+=(--include /tests/api/env/.env.example)
for e in "${EXCLUDES[@]}"; do RSYNC_ARGS+=(--exclude "/$e"); done
[ "$DRY_RUN" = 1 ] && RSYNC_ARGS+=(--dry-run)

echo ">>> Uploading $ENGINE_DIR -> ${TARGET}:${REMOTE_DIR}"
# The upload needs rsync on the host too; Debian's cloud images do not ship it.
ssh "$TARGET" "mkdir -p '$REMOTE_DIR' && { command -v rsync >/dev/null ||
    { apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq rsync >/dev/null; }; }"
rsync "${RSYNC_ARGS[@]}" "${ENGINE_DIR}/" "${TARGET}:${REMOTE_DIR}/"

if [ "$DRY_RUN" = 1 ]; then
    echo ">>> Dry run — not bootstrapping."
    exit 0
fi

# `version` is excluded above, so an earlier install's commit would outlive the
# upload and updates would compare against it. Name this tree's commit, or
# none when the tree has changes no commit holds.
if [ -z "$(git -C "$ENGINE_DIR" status --porcelain 2>/dev/null)" ] &&
    COMMIT=$(git -C "$ENGINE_DIR" rev-parse --verify HEAD 2>/dev/null); then
    ssh "$TARGET" "printf '%s\n' $COMMIT >$(printf '%q' "${REMOTE_DIR}/version")"
else
    echo ">>> The tree has uncommitted changes or no git history; removing ${REMOTE_DIR}/version"
    ssh "$TARGET" "rm -f $(printf '%q' "${REMOTE_DIR}/version")"
fi

echo ">>> Bootstrapping on ${TARGET}"
# ssh joins its arguments into one string for the remote shell, so quote each
# bootstrap argument: `--services "core mail"` must arrive as two words, not three.
REMOTE_ARGS=''
[ ${#BOOTSTRAP_ARGS[@]} -gt 0 ] && REMOTE_ARGS=$(printf ' %q' "${BOOTSTRAP_ARGS[@]}")
ssh -t "$TARGET" "bash $(printf '%q' "${REMOTE_DIR}/scripts/bootstrap-from-source.sh")${REMOTE_ARGS}"

#!/usr/bin/env bash
# Sync the working tree to a test engine host.
#
#   scripts/sync-engine.sh root@2.29.1.58 [--check]
#
# The engine's `core` is bind-mounted (`./core -> /var/www/html`), so files
# land in the running container the moment they are copied -- no rebuild, no
# restart. php-fpm picks up changed PHP on the next request, which is what
# makes iterating on the engine against a live host cheap. Queue workers keep
# the code they booted with until `php artisan queue:restart`.
#
# Excluded, and why:
#   vendor/      installed by the image; the host has no composer
#   node_modules/
#   core/storage/ a docker *volume* (`core-storage`), so anything written here
#                 is shadowed by the volume and syncing it achieves nothing
#   .git/        the deployed tree is not a git checkout
#   logs/
#   users/       runtime output -- one directory per hosted account, created by
#   webserver-config/  the engine as it deploys. Root-owned locally (the test
#   crt/  data/  pureftpd/  containers wrote them), so rsync fails on them, and
#                 the remote host generates its own. These hold TLS keys, ssh
#                 host keys and FTP state -- per-host secrets that must not be
#                 copied between hosts even when they are readable. None is
#                 engine source.
set -euo pipefail

REMOTE="${1:?usage: sync-engine.sh root@HOST [--check]}"
CHECK="${2:-}"
ENGINE_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)"
DEST='/opt/panelalpha/shared-hosting'

EXCLUDES=(
  --exclude='.git/'
  --exclude='vendor/'
  --exclude='node_modules/'
  --exclude='core/storage/'
  --exclude='logs/'
  --exclude='users/'
  --exclude='webserver-config/'
  --exclude='crt/'
  --exclude='data/'
  --exclude='pureftpd/'
  --exclude='config/sftp/ssh_host_*'
  --exclude='*.log'
  --exclude='.aider*'
  --exclude='.env'
  --exclude='.env-core'
)

# --check reports what differs without writing. Used to answer "is the host
# actually running what I think it is" before trusting a measurement.
if [[ "$CHECK" == '--check' ]]; then
  echo "=== differences on $REMOTE (no changes written) ==="
  rsync -rlptn --itemize-changes "${EXCLUDES[@]}" "$ENGINE_DIR/" "$REMOTE:$DEST/" \
    | grep -vE '^\.d|^$' | head -60
  echo "=== end (empty means identical) ==="
  exit 0
fi

echo "=== syncing $ENGINE_DIR -> $REMOTE:$DEST ==="
rsync -rlpt --info=stats2 "${EXCLUDES[@]}" "$ENGINE_DIR/" "$REMOTE:$DEST/"

# The files the recent engine fixes live in. Verified by checksum because a
# silent rsync failure would otherwise be read as "the fix did not work".
echo
echo "=== checksums (local vs remote) ==="
STATUS=0
for f in \
  core/app/Lib/Task/TaskReconciler.php \
  core/app/Console/Commands/Task/ReconcileTasksCommand.php \
  core/app/Console/Kernel.php \
  core/app/Lib/Deploy/Platform/Runtime/JsPackageManager.php \
  core/app/Lib/Deploy/DeployLog/FailureOutput.php \
  core/app/Lib/Deploy/Platform/Runtime/Php/PhpHostBuild.php \
  core/app/Http/Controllers/UserController.php \
  scripts/app-support-batch.py \
  config/core/images.yaml
do
  L=$(md5sum "$ENGINE_DIR/$f" 2>/dev/null | cut -d' ' -f1)
  R=$(ssh -o ConnectTimeout=15 "$REMOTE" "md5sum $DEST/$f 2>/dev/null | cut -d' ' -f1")
  if [[ "$L" == "$R" && -n "$L" ]]; then
    echo "  OK    $f"
  else
    echo "  DIFF  $f  local=${L:-none} remote=${R:-none}"
    STATUS=1
  fi
done

exit $STATUS

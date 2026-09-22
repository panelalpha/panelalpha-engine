#!/bin/bash
# PanelAlpha wrapper for the official Crafty Controller image.
#
# 1. Start the stunnel TLS bridge (plain HTTP :8000 -> TLS :8443) in the
#    background so the engine's proxy can reach Crafty's HTTPS-only server.
# 2. Capture the admin password Crafty generates on first boot into the owner's
#    persistent store, under a clear name (the config dir is already the
#    persistent bind mount, so default-creds.txt is on ~/.panelalpha/crafty too).
# 3. Hand off to Crafty's own launcher, unchanged, with its default args.
set -u

# --- 1. TLS bridge ---------------------------------------------------------
stunnel4 /etc/stunnel/panelalpha.conf &

# --- 2. Capture the generated admin credential -----------------------------
# Never printed to stdout/stderr - only written into the owner's 0600 store.
(
  src=/crafty/app/config/default-creds.txt
  dst=/crafty/app/config/panelalpha-admin-credentials.txt
  i=0
  while [ "$i" -lt 150 ]; do
    if [ -f "$src" ]; then
      cp -f "$src" "$dst" 2>/dev/null || true
      chmod 600 "$src" "$dst" 2>/dev/null || true
      break
    fi
    i=$((i + 1))
    sleep 2
  done
) &

# --- 3. Crafty's own launcher (repairs bind-mount perms, drops to the crafty
#        user, activates the venv, runs main.py) ------------------------------
exec /crafty/docker_launcher.sh "$@"

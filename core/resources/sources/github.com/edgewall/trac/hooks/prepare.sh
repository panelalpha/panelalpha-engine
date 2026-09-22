#!/bin/bash
set -e
cd ~/project

# The project environment must survive a redeploy, which wipes ~/project and
# rebuilds the image. ~/.panelalpha survives it (Project::createHomeDirectory
# only mkdir -p's the home tree), so the app's /data bind mount points there.
# Record the absolute host path for compose to interpolate in the override. The
# directory itself is created by the Docker daemon when it binds the mount —
# ~/.panelalpha is root-owned, so this hook (run as the account user) cannot and
# must not create it.
touch .env
grep -q '^TRAC_DATA=' .env || printf 'TRAC_DATA=%s/.panelalpha/trac\n' "$HOME" >> .env

#!/bin/bash
# Seed the directories Indexhibit writes into (config written by its installer,
# uploaded images) into ~/.panelalpha once; the override mounts them back.
set -e
cd ~/project
DATA="${HOME}/.panelalpha/indexhibit"
mkdir -p "$DATA"
chmod 700 "$DATA"
[ -d "$DATA/config" ] || cp -a ndxzsite/config "$DATA/config"
[ -d "$DATA/files" ] || cp -a files "$DATA/files"

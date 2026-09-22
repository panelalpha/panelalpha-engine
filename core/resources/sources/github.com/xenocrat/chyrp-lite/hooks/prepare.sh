#!/bin/bash
# Account shell, after the clone and before detection and the build. Nothing
# here can know the database credentials or the account's public URL -- the
# engine provisions those while it writes the compose file, after this hook --
# so everything that needs them is in files/panelalpha-install.php instead.
set -e
cd ~/project

# ~ itself is root-owned 0755 and an account cannot create a file directly in
# it; ~/.panelalpha is created with the account and belongs to it. The compose
# override bind-mounts this directory at /data, and it has to exist before the
# container starts or Docker creates it as root.
DATA_HOME="${HOME}/.panelalpha/chyrp-lite"
mkdir -p "${DATA_HOME}/uploads"
chmod 700 "${DATA_HOME}"

# ---------------------------------------------------------------------------
# 1. Upstream's container files, which describe a different deployment.
#
# docker-compose.yaml is why the engine chose the `compose` strategy for this
# repository (compose-usable at priority 980 beats php-plain's 200): it builds
# the root Dockerfile, publishes 127.0.0.1:8080:80 and mounts two named
# volumes. It is a real single-service production compose rather than a
# workstation stack -- but it is a *container* deployment, and what this
# account has is a hosting account: the shared php:8.4-apache image, ~/project
# bind-mounted and editable over SFTP, the account's own MySQL server visible
# in phpMyAdmin, and the engine's vhost with its deny rules. panelalpha.yaml
# pins `php-plain`, which PlatformSelector::fromSource() resolves ahead of the
# detection walk either way; this is about what is left lying in the document
# root.
#
# Dockerfile and entrypoint.sh would be served as plain text from the project
# root (the vhost denies dotfiles, docker-compose.ya?ml and panelalpha-*, and
# nothing else), and neither runs here. Upstream's own installer deletes all
# four when it finishes; they are moved rather than deleted so the files the
# account cloned are still readable.
#
# Named one by one, never as a glob. `docker-compose.*` is the obvious
# spelling and it is WRONG: by the time this hook runs the engine has already
# written overrides/docker-compose.override.yml into the project root under
# exactly that name -- the order is clone, copy files/ and overrides/, run this
# hook, then detect -- so a glob moves this recipe's own override aside and the
# deploy quietly loses its healthcheck while still reporting success
# (engine#166).
mkdir -p .panelalpha
for f in docker-compose.yaml Dockerfile entrypoint.sh .dockerignore; do
    [ -e "$f" ] || continue
    mv -f "$f" ".panelalpha/upstream-${f}"
    echo "[chyrp-lite] moved $f out of the project root"
done

# ---------------------------------------------------------------------------
# 2. uploads/, which is where the blog's images go.
#
# Chyrp's uploads_path is MAIN_DIR . "/uploads/" and MAIN_DIR is dirname of
# index.php -- inside the checkout, which a redeploy clears and re-clones
# (engine#173). A symlink out of the document root is not an option here:
# uploads are served by Apache as static files, and the generated vhost's
# `<Directory /> Require all denied` matches the *resolved* path, so anything
# pointing outside the document root answers 403.
#
# So the directory stays exactly where Chyrp expects it and the compose
# override bind-mounts ~/.panelalpha/chyrp-lite/uploads over it. Its content
# then lives on the account's own disk and a redeploy cannot touch it. The
# mount point has to exist in the clone or Docker would create it on the host
# as root; upstream ships uploads/ with a .gitignore in it, and this is the
# belt and braces.
mkdir -p uploads

# ---------------------------------------------------------------------------
# 3. The directories Chyrp writes to at runtime.
#
# includes/caches/{twig,thumbs} are upstream's, and install.php refuses to run
# if any of them is not writable. They are in the clone already, so this is
# about the mode rather than the existence; the container runs as this same
# account uid (the generated compose file says `user: "<uid>:<gid>"`).
mkdir -p includes/caches/twig includes/caches/thumbs
chmod 755 includes/caches includes/caches/twig includes/caches/thumbs

echo "[chyrp-lite] prepared; data directory is ${DATA_HOME}"

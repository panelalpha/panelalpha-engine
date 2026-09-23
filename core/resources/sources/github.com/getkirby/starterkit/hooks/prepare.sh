#!/bin/bash
# Kirby is flat-file: its database is directories inside the checkout. ~/project
# is emptied and re-cloned on every deploy (engine#173, ProjectTree::clearContents),
# so content/, site/accounts/ and site/config/ cannot live there or a redeploy
# destroys every page, every Panel account and the content salt -- which is why
# #844 was rejected. They live in ~/.panelalpha/kirby (owner-owned, survives a
# rebuild, the only writable place outside ~/project since the home is root:root)
# and are symlinked back into the fresh checkout here. Runs after the clone,
# before the container, as the account user (execAsUser).
set -euo pipefail

STATE="$HOME/.panelalpha/kirby"
PROJECT="$HOME/project"

# ~/.panelalpha is scaffolded by the engine and owned by the account; kirby/ is
# ours to create. 0700: nothing here is web content and it holds secrets.
mkdir -p "$STATE"
chmod 700 "$STATE"

# --- content: all pages and files. Seed once from the shipped demo content,
# then it belongs to the owner and is never touched again.
if [ ! -e "$STATE/content" ]; then
    cp -a "$PROJECT/content" "$STATE/content"
fi

# --- config: holds content.salt (a real per-install secret, so file/preview
# tokens survive a redeploy instead of being re-derived from the path -- see
# kirby/src/Cms/App.php:441) and, once activated, .license. Seed once, then
# write a production config: debug off (a public site must not print stack
# traces) and a random content.salt.
if [ ! -e "$STATE/config" ]; then
    cp -a "$PROJECT/site/config" "$STATE/config"
    SALT="$(openssl rand -hex 32 2>/dev/null || head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    cat > "$STATE/config/config.php" <<PHP
<?php

// Written once by the PanelAlpha Kirby recipe and then persisted; upstream's
// own config.php is not carried across updates because this directory is now
// the source of truth. content.salt is a per-install secret Kirby would
// otherwise derive from the site path.
return [
    'debug'        => false,
    'yaml.handler' => 'symfony',
    'content.salt' => '${SALT}',
];
PHP
fi

# --- accounts + sessions: empty until the Panel writes to them at runtime.
mkdir -p "$STATE/accounts" "$STATE/sessions"

# --- the admin the start stage creates before Apache binds. Generate the
# password once and keep it (0600) so the owner can always read it back; it is
# outside the document root and never web-reachable.
if [ ! -e "$STATE/admin.pw" ]; then
    ( umask 077
      openssl rand -base64 18 > "$STATE/admin.pw" 2>/dev/null \
        || head -c 18 /dev/urandom | base64 > "$STATE/admin.pw" )
fi
if [ ! -e "$STATE/admin.email" ]; then
    printf '%s\n' "${PA_KIRBY_ADMIN_EMAIL:-admin@example.com}" > "$STATE/admin.email"
fi
chmod 600 "$STATE/admin.pw" "$STATE/admin.email"

printf '[panelalpha] kirby Panel admin: %s\n' "$(cat "$STATE/admin.email")" >&2
printf '[panelalpha] kirby Panel password: %s\n' "$(cat "$STATE/admin.pw")" >&2

# --- the in-container init script. Lives in the persisted store (mounted at
# /data), outside the document root. Creates the first Panel user so
# /panel/installation is closed before Apache binds (engine#200). Idempotent.
cat > "$STATE/pa-kirby-init.php" <<'PHP'
<?php
// Runs in the app container at the start stage, before the serve command execs
// Apache. index is pinned to /app so Kirby reads the checkout (content/, site/
// are symlinks into /data from there).
require '/app/kirby/bootstrap.php';

$kirby = new Kirby(['roots' => ['index' => '/app']]);
$kirby->impersonate('kirby');

if ($kirby->users()->count() > 0) {
    fwrite(STDERR, "[panelalpha] Panel already has a user; leaving it\n");
    exit(0);
}

$email = trim(@file_get_contents('/data/admin.email') ?: 'admin@example.com');
$pw    = trim(@file_get_contents('/data/admin.pw') ?: '');
if ($pw === '') {
    fwrite(STDERR, "[panelalpha] no admin password on disk; refusing to create a user\n");
    exit(1);
}

$kirby->users()->create([
    'email'    => $email,
    'role'     => 'admin',
    'name'     => 'Administrator',
    'language' => 'en',
    'password' => $pw,
]);
fwrite(STDERR, "[panelalpha] created Panel admin {$email}\n");
PHP

# --- redirect the stateful paths in the fresh checkout at the persisted store.
# Targets are the container path (/data), where the compose override mounts
# ~/.panelalpha/kirby. On the host these symlinks dangle, which is fine: only
# the container and Kirby read through them, and Apache serves content via
# index.php (PHP file reads), never the symlink target directly.
link_state() {
    rm -rf "$1"
    ln -s "$2" "$1"
}
link_state "$PROJECT/content"       /data/content
link_state "$PROJECT/site/config"   /data/config
link_state "$PROJECT/site/accounts" /data/accounts
link_state "$PROJECT/site/sessions" /data/sessions

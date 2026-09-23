#!/bin/sh
# Installs the GP247 platform, once.
#
# `php artisan migrate` builds the three tables of the Laravel skeleton and
# nothing else: every gp247_* table, the seeded menus, roles, permissions,
# languages, store and admin user, the GP247Front template published into
# app/GP247 and public/GP247, and storage:link, all come from `gp247:install`
# (GP247\Core\Commands\InstallAll), which runs gp247:core-install ->
# gp247:front-install -> gp247:shop-install.
#
# --force=1 is required because the command refuses to prompt in a
# non-interactive session: "Refusing to install without confirmation. Pass
# --force=1 for unattended install."
#
# It is also why this needs a guard. --force=1 skips the confirmation gate
# rather than making the command idempotent, and front-install and shop-install
# each call their own uninstall first -- the command's own docblock says so:
# "running this on a live site destroys front/shop data". An `install` stage
# that fires again on a redeploy, or an `upgrade` stage after a git pull, would
# drop and recreate the storefront and the whole shop.
#
# The marker is GP247's own: Storage::disk('local')->put('gp247-installed.txt'),
# written by gp247:core-install as its last step and read by the command itself
# to decide whether it has run. Laravel 11 moved the local disk's root to
# storage/app/private, so both locations are checked -- the older one so a
# project installed under an earlier layout is still recognised.
set -e

if [ -f storage/app/private/gp247-installed.txt ] || [ -f storage/app/gp247-installed.txt ]; then
    echo "[s-cart] GP247 is already installed (gp247-installed.txt); skipping gp247:install"
    exit 0
fi

echo "[s-cart] installing GP247 (core -> front -> shop)"

# APP_ENV=local for this one process, and it is what makes the install work at
# all. None of the three install commands passes --force to the migrations it
# runs -- `$this->runArtisan('migrate', ['--path' => ...])`, in
# GP247\Core\Commands\Install, GP247\Front\Commands\FrontInstall and
# GP247\Shop\Commands\ShopInstall alike -- and Laravel's ConfirmableTrait stops
# an unforced migration in production. In a non-interactive session the
# confirmation defaults to no, so what the deploy log shows is:
#
#     APPLICATION IN PRODUCTION.
#     WARN  Command cancelled.
#     ---------------> Migrate default done!
#
# three cancelled migrations reported as successes, followed by the seeders
# running against tables that were never created:
#
#     SQLSTATE[42S02]: Base table or view not found: 1146
#     Table 'scart.gp247_admin_menu' doesn't exist
#
# Measured, on the first deploy of this recipe. The seeders themselves pass
# `--force => true`, so the omission is only on the migrate calls; there is no
# flag or environment variable that supplies it from outside, and nothing in
# the platform can replace a command inside a vendor package. Telling the
# framework that this one process is not production is the narrowest lever
# there is: it lasts for the length of the install, it is not written to .env,
# and every request the account serves afterwards is APP_ENV=production, which
# is what the compose file's `environment:` says.
#
# Nothing in the install depends on the environment name otherwise -- the store
# domain the seeders record comes from APP_URL, not from APP_ENV. Verified on
# a full run: with this line the whole chain completes and `/gp247_admin`
# renders; without it, it does not.
APP_ENV=local php artisan gp247:install --force=1

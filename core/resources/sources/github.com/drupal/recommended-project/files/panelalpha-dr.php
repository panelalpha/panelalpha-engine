<?php

/**
 * @file
 * A working entry point for Drupal's own CLI, `dr`.
 *
 * `vendor/bin/dr` is broken on this project, and it is not Drupal's fault.
 * Composer's BinaryInstaller skips a binary link that already exists, and by
 * the time the application container reinstalls `drupal/core` into `web/core`
 * the host build has already written `vendor/bin/dr` pointing at
 * `../drupal/core/scripts/dr` -- the plugin-less location. Running it gives
 *
 *     PHP Warning: include(/app/vendor/bin/../drupal/core/scripts/dr):
 *     Failed to open stream: No such file or directory
 *
 * and after the install stage removes that leftover directory the path is gone
 * for good. Measured on this host.
 *
 * `web/core/scripts/dr` cannot simply be run instead: it reaches for
 * `$GLOBALS['_composer_autoload_path']`, which only Composer's own bin proxy
 * sets, and falls back to `__DIR__/../../vendor/autoload.php` -- i.e.
 * `web/vendor/autoload.php`, which does not exist in a relocated-docroot
 * project. Setting the global is the whole of the fix.
 *
 * Usage, from inside the container or over SSH:
 *
 *     php ~/project/panelalpha-dr.php system:status
 *     php ~/project/panelalpha-dr.php cache:rebuild
 *     php ~/project/panelalpha-dr.php user:login          # one-time login link
 *     php ~/project/panelalpha-dr.php recipe:apply core/recipes/article_comment
 *
 * Outside the document root, and named so the base image's
 * `<FilesMatch "^(?:docker-compose\.ya?ml|panelalpha[-.])">` denies it as well.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$GLOBALS['_composer_autoload_path'] = '/app/vendor/autoload.php';
// symfony/runtime reads this during startup to decide which file it is
// running; without it the deprecation shim in core/scripts/drupal re-enters.
$_SERVER['SCRIPT_FILENAME'] = '/app/web/core/scripts/dr';
// Every Drupal CLI command resolves `sites/default` relative to the working
// directory.
chdir('/app/web');

return require '/app/web/core/scripts/dr';

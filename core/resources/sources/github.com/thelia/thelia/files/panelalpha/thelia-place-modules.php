<?php
/**
 * Makes Thelia's modules visible where Thelia looks for them.
 *
 * composer.json maps `type:thelia-module` to `vendor/{$vendor}/modules/{$name}`
 * in `extra.installer-paths`, and the plugin that reads that key is
 * composer/installers. The php manifest installs with `--no-plugins` and
 * PhpHostBuild::mayRunPlugins() lifts the flag only for a project with a
 * composer.lock; thelia/thelia commits none, so Composer fell back to its own
 * LibraryInstaller and all sixteen modules are at `vendor/thelia/<package>`.
 *
 * core/bootstrap.php defines THELIA_MODULE_DIR as `vendor/thelia/modules/`,
 * and DatabaseSetup::registerAndApplyModules() scans that and local/modules/
 * and nothing else — so the shop installs with no payment module, no delivery
 * module and no template engine. Measured on the deploy before this file
 * existed: `0 module(s) registered`.
 *
 * ## Why a symlink and not a move
 *
 * Because Composer has to keep finding each package where it put it. Composer
 * 2 resolves a package's own autoload rules, and the `--no-dev` reachability
 * walk that decides which packages reach the autoloader at all, against the
 * install path it recorded — and a package it cannot find is dropped along
 * with everything reachable only through it. Moving the directories cost two
 * things, both measured:
 *
 *   - thelia/tiptap-module declares psr-4 `Tiptap\` at its own root, so the
 *     optimized classmap pointed at the directory it had just been moved out
 *     of: `Failed opening '/app/vendor/composer/../thelia/tiptap-module/
 *     Tiptap.php'`, and the kernel died on `Class "Tiptap\Tiptap" not found`.
 *   - thelia/thelia-library-module is the only package that requires
 *     liip/imagine-bundle, and moving it took liip out of the autoloader
 *     entirely: `Class "Liip\ImagineBundle\LiipImagineBundle" not found` while
 *     registering the bundles in config/bundles.php. Rewriting the recorded
 *     install-path did not help — Composer drops a package whose *recorded*
 *     path disagrees with the installer's idea of where it belongs.
 *
 * A symlink is the only arrangement where both are true at once: the package
 * is still at `vendor/thelia/<package>` for Composer, and it is also at
 * `vendor/thelia/modules/<Name>` for Thelia. The root composer.json maps psr-4
 * `""` to `local/modules/` and `vendor/thelia/modules`, so a module class
 * resolves through either path, and `DirectoryIterator` treats a symlink to a
 * directory as a directory.
 *
 * Relative, not absolute: the checkout is bind-mounted at /app in the
 * container and at ~/project on the host, and an absolute link made in one is
 * a dangling link in the other — in the account's backup, in an operator's
 * shell, in the engine's own walk of the tree.
 *
 * Idempotent: a link that is already there is left alone.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

chdir(__DIR__ . '/..');

const MODULE_DIR = 'vendor/thelia/modules';

$installedJson = 'vendor/composer/installed.json';
if (!is_file($installedJson)) {
    fwrite(STDERR, "[thelia] no vendor/composer/installed.json; did the build run?\n");
    exit(1);
}

// Decoded as objects rather than associative arrays: nothing here writes the
// file back, and reading it the way Composer wrote it keeps that true by
// construction.
$decoded = json_decode((string) file_get_contents($installedJson));
if (!is_object($decoded) || !isset($decoded->packages) || !is_array($decoded->packages)) {
    fwrite(STDERR, "[thelia] vendor/composer/installed.json is not in the shape Composer 2 writes\n");
    exit(1);
}

/**
 * The path of $target as seen from inside $from, both relative to the project
 * root and both known to exist.
 */
function relativeTo(string $from, string $target): string
{
    $fromParts = explode('/', trim($from, '/'));
    $targetParts = explode('/', trim($target, '/'));

    while ($fromParts !== [] && $targetParts !== [] && $fromParts[0] === $targetParts[0]) {
        array_shift($fromParts);
        array_shift($targetParts);
    }

    return str_repeat('../', count($fromParts)) . implode('/', $targetParts);
}

$linked = 0;
$present = 0;

foreach ($decoded->packages as $package) {
    if (!is_object($package) || ($package->type ?? null) !== 'thelia-module') {
        continue;
    }

    // The same value composer/installers substitutes for {$name}, and never a
    // name invented here: Thelia registers a module by its directory name and
    // resolves its class through Config/module.xml's <fullnamespace>, so a
    // directory that does not match is a module that cannot be loaded.
    $name = $package->extra->{'installer-name'} ?? null;
    $path = $package->{'install-path'} ?? null;
    if (!is_string($name) || $name === '' || !is_string($path) || $path === '') {
        continue;
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        fwrite(STDERR, "[thelia] refusing an installer-name that is not a plain directory name: {$name}\n");
        continue;
    }

    $link = MODULE_DIR . '/' . $name;
    if (file_exists($link) || is_link($link)) {
        ++$present;
        continue;
    }

    $source = realpath('vendor/composer/' . $path);
    $root = realpath('.');
    if ($source === false || $root === false || !is_dir($source) || !str_starts_with($source, $root . '/')) {
        continue;
    }

    if (!is_dir(MODULE_DIR) && !mkdir(MODULE_DIR, 0755, true) && !is_dir(MODULE_DIR)) {
        fwrite(STDERR, '[thelia] could not create ' . MODULE_DIR . "\n");
        exit(1);
    }

    if (!symlink(relativeTo(MODULE_DIR, substr($source, strlen($root) + 1)), $link)) {
        fwrite(STDERR, "[thelia] could not link {$link}\n");
        exit(1);
    }

    ++$linked;
}

if ($linked === 0) {
    echo "[thelia] {$present} module(s) already in vendor/thelia/modules\n";
    exit(0);
}

echo "[thelia] linked {$linked} module(s) into vendor/thelia/modules\n";

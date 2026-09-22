<?php
/**
 * Registers the bundles Symfony Flex would have registered.
 *
 * config/bundles.php is a *generated* file: Flex's BundlesConfigurator writes a
 * line into it every time a package whose recipe declares a bundle is
 * installed. thelia/thelia's .gitignore lists `/config/`, so the copy in the
 * repository is only what someone chose to `git add -f`, and it has fallen
 * behind the package set — it names twenty bundles and misses three that the
 * Flexy front office and the Twig back office need. With `--no-plugins` Flex
 * never runs, so nothing catches up.
 *
 * What that cost, measured: `templates/frontOffice/flexy/components/Layouts/
 * Header/Base.html.twig` uses the `tailwind_merge` filter, and with
 * tales-from-a-dev/twig-tailwind-extra unregistered every front-office page
 * answered HTTP 500 with `Unknown "tailwind_merge" filter` — after the shop was
 * fully installed and while /admin/login answered 200. The two SymfonyCasts
 * bundles are the `tailwind:build` and `sass:build` commands: bin/install runs
 * both and treats them as optional *because the command may not exist*, so
 * without the bundles it skipped them silently and the themes' stylesheets were
 * never built.
 *
 * Adaptive rather than a replacement config/bundles.php: each entry is added
 * only when its class is really installed and not already registered, so a
 * Thelia that registers them itself, or a project that does not ship the
 * package, is left exactly as it is. The write goes through Thelia's own
 * ComposerHelper::addNamespaceToBundlesSymfony(), which is what template:set
 * uses, so the file keeps the shape and the sort order Flex gives it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

chdir(__DIR__ . '/..');

require 'vendor/autoload.php';

/**
 * Bundle class => the environments to register it for, in the form
 * config/bundles.php uses. All three are runtime bundles of packages
 * thelia/flexy and thelia/backoffice-default-twig-template require, so `all`
 * is what their own recipes declare.
 */
$bundles = [
    // The `tailwind_merge` Twig filter, used by Flexy's layout components.
    'TalesFromADev\Twig\Extra\Tailwind\Bridge\Symfony\Bundle\TalesFromADevTwigExtraTailwindBundle' => ['all' => true],
    // `tailwind:build`, which compiles the front office's stylesheet from the
    // theme's assets/styles/app.css. Without it AssetMapper cannot resolve the
    // `tailwindcss` asset that file imports.
    'Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle' => ['all' => true],
    // `sass:build`, for the Twig back office's stylesheets.
    'Symfonycasts\SassBundle\SymfonycastsSassBundle' => ['all' => true],
];

$registered = require 'config/bundles.php';
$helper = new Thelia\Domain\Module\Composer\ComposerHelper();
$added = [];

foreach ($bundles as $class => $environments) {
    if (isset($registered[$class])) {
        continue;
    }
    if (!class_exists($class)) {
        // The package is not installed in this project. Nothing to register,
        // and nothing wrong: the theme that wanted it is not the active one.
        continue;
    }

    $helper->addNamespaceToBundlesSymfony($class, $environments);
    $added[] = $class;
}

if ($added === []) {
    echo "[thelia] no bundle to register\n";
    exit(0);
}

echo '[thelia] registered ' . count($added) . " bundle(s) Flex would have: " . implode(', ', $added) . "\n";

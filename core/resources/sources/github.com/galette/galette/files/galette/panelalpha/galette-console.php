<?php

/**
 * PanelAlpha launcher for Galette's own console (galette/galette).
 *
 * Upstream's bin/console assumes the un-flattened repository layout (bin/ with a
 * galette/ sibling); app_root: galette flattens the app to /app, so bin/console
 * is not in the container. This runs Galette's OWN \Galette\Console\GaletteApplication
 * with GALETTE_ROOT = /app, which is the galette/ tree. It is a launcher, not a
 * change to any upstream file.
 */

declare(strict_types=1);

$basepath = '/app/';

define('GALETTE_ENV', 'CLI');
define('GALETTE_ROOT', $basepath);

// Config lives at /app/config (symlinked onto persistent storage by
// galette-setup.sh); leave paths.inc.php to default the rest off GALETTE_ROOT.
$logfile = 'galette_cli';

require_once $basepath . 'vendor/autoload.php';
require_once $basepath . 'includes/sys_config/versions.inc.php';

// Force installer mode so galette.inc.php includes only the parts that work
// against an empty (or freshly configured) database, exactly as bin/console does.
$installer = true;
if (!defined('GALETTE_INSTALLER')) {
    define('GALETTE_INSTALLER', true);
}

require_once $basepath . 'includes/galette.inc.php';

session_start();
$session_name = 'galette_cli_' . str_replace('.', '_', GALETTE_VERSION);
$session = &$_SESSION['galette'][$session_name];

$gapp = new \Galette\Core\SlimApp();
$app = $gapp->getApp();

require_once $basepath . 'includes/dependencies.php';

$console = new \Galette\Console\GaletteApplication($basepath);
$console->init();
$console->run();

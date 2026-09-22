<?php
/**
 * Craft's front controller, written by PanelAlpha.
 *
 * craftcms/cms is a Composer *library* -- `"type"` unset, `"name":
 * "craftcms/cms"`, psr-4 `craft\` => `src/` -- and ships no entry point of its
 * own: no web/, no index.php, no `craft` console script. Those live in
 * craftcms/craft, the starter project a site is normally created from, which
 * requires this package and is three files and a config directory around it.
 *
 * This is the same file craftcms/craft ships, with one difference. There, Craft
 * is a dependency, so the bootstrap is reached through
 * `vendor/craftcms/cms/bootstrap/web.php`. Here the checkout *is* craftcms/cms,
 * so the bootstrap is reached at its own path and CRAFT_VENDOR_PATH has to be
 * stated: bootstrap/bootstrap.php otherwise guesses `dirname(__DIR__, 3)` from
 * its own location, which is correct only when it sits under vendor/.
 *
 * No Dotenv here, and that is deliberate rather than an omission. craftcms/craft
 * loads vlucas/phpdotenv in its bootstrap; craftcms/cms has that package in
 * require-dev, and the engine installs with --no-dev, so the class is not there
 * to call. It is not needed either: the generated compose file passes DB_*,
 * APP_URL and everything else as real container environment variables, and
 * `craft\helpers\App::env()` reads getenv() directly.
 */

define('CRAFT_BASE_PATH', dirname(__DIR__));
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

// Craft reads its licence-key path from a PHP *constant*, not from the
// environment: Path::getLicenseKeyPath() is
// `defined('CRAFT_LICENSE_KEY_PATH') ? CRAFT_LICENSE_KEY_PATH : config/license.key`,
// while bootstrap/bootstrap.php checks the directory named by the *env var* of
// the same name. Setting only the variable therefore moves the writability
// probe and leaves the real key in the checkout, which a redeploy deletes. So
// the value arrives as an environment variable (panelalpha.yaml) and is
// promoted to the constant here, before the bootstrap runs. App::env() falls
// back to constants, so the probe still agrees.
$licensePath = getenv('CRAFT_LICENSE_KEY_PATH');
if (is_string($licensePath) && $licensePath !== '') {
    define('CRAFT_LICENSE_KEY_PATH', $licensePath);
}

require_once CRAFT_VENDOR_PATH . '/autoload.php';

/** @var craft\web\Application $app */
$app = require CRAFT_BASE_PATH . '/bootstrap/web.php';
$app->run();

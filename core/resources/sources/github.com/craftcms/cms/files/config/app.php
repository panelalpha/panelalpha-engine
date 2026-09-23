<?php
/**
 * Yii application config, written by PanelAlpha. Merged over
 * src/config/app.php, which hardcodes `'id' => 'CraftCMS'`.
 *
 * The id is what Craft keys its session cookie, cache entries and mutex locks
 * on. Left at the default, `setup/keys` -- which `install` runs -- decides the
 * install needs one and writes CRAFT_APP_ID into .env, where the engine then
 * copies it into a world-readable .env.default. Supplying it from the
 * environment instead (hooks/prepare.sh generates it once per account, into
 * ~/.panelalpha/) means that branch never fires.
 *
 * Same file craftcms/craft ships, for the same reason.
 */

use craft\helpers\App;

return [
    'id' => App::env('CRAFT_APP_ID') ?: 'CraftCMS',
];

<?php
/**
 * General settings, written by PanelAlpha.
 *
 * Deliberately close to the file craftcms/craft ships -- that file is Craft's
 * own opinion about a production site and there is no reason to disagree with
 * it -- plus the two things a hosting account knows and a starter project does
 * not: where the site lives, and where its generated control-panel resources
 * go.
 *
 * Anything set as a CRAFT_* environment variable still wins:
 * Config::_createConfigObj() applies App::envConfig(GeneralConfig::class,
 * 'CRAFT_') *after* this file, through the fluent setter when one exists. That
 * is how CRAFT_SECURITY_KEY reaches Craft without being written into the
 * checkout (see hooks/prepare.sh), and how an operator overrides any of this
 * from the panel's env_vars.
 */

use craft\config\GeneralConfig;
use craft\helpers\App;

$aliases = [
    // Where the control panel's own JS and CSS are published to. The default
    // is @webroot/cpresources, which is this same directory -- named because
    // @webroot is named, and because getting it wrong produces an unstyled
    // control panel that reads like a broken install rather than a wrong path.
    '@webroot' => dirname(__DIR__) . '/web',
];

// The public address of the account, as the engine wrote it into the container
// (PhpEnvironment::url()). @web is what Craft falls back to when it has to
// build an absolute URL and nothing else has said what the site is called;
// leaving it to be derived from the request is what makes a CMS poisonable
// through the Host header. Omitted rather than set empty when there is no
// domain yet -- Craft's own fallback is better than an alias that is ''.
$appUrl = App::env('APP_URL');
if (is_string($appUrl) && $appUrl !== '') {
    $aliases['@web'] = rtrim($appUrl, '/');
}

return GeneralConfig::create()
    // Craft's own production defaults, from craftcms/craft's config/general.php.
    ->defaultWeekStartDay(1)
    ->omitScriptNameInUrls()
    ->preloadSingles()
    ->preventUserEnumeration()
    ->enableTwigSandbox()
    ->aliases($aliases);

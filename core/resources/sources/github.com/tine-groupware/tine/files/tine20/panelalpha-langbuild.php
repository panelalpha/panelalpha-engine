<?php
declare(strict_types=1);

// The per-application, per-locale client translation files, which a checkout
// does not ship and which tine cannot render a single page without.
//
// This is upstream's `translation-build` phing task (tine20/build.xml:392-447),
// reimplemented in about thirty lines because reaching the original means
// `vendor/bin/phing`, which is in require-dev, which the host build does not
// install (`composer install --no-dev`).
//
// WHY IT IS NOT OPTIONAL. Once the webpack build has written
// Tinebase/js/webpack-assets-FAT.json, Tinebase_Core::detectBuildType()
// answers RELEASE -- and in RELEASE
// Tinebase_Frontend_Http::getJsTranslations() stops generating translations on
// the fly and serves prebuilt `<App>/js/<App>-lang-<locale>.js` files instead
// (Tinebase/Frontend/Http.php:175-186). `.gitignore` excludes
// `tine20/*/js/*-lang-*`, so in a checkout those files do not exist and the
// endpoint returns 200 with a zero-byte body -- measured. The client then
// never sees `Tine.__translationData.__isLoaded`, waits ten seconds for it, and
// puts up "A problem with the translations was detected. Trying to reload the
// client..." before reloading into the same wait, for ever
// (Tinebase/js/tineInit.js:1488-1497). The application is 200 OK, the health
// probe passes, and nobody can use it. This file is what stops that.
//
// It generates the same content the DEVELOPMENT path would have generated at
// request time, by calling upstream's own Tinebase_Translation::
// getJsTranslations() -- so it is not a reimplementation of the translations,
// only of the loop that writes them out.
//
// Not minified. The phing task pipes each file through jsMin (which needs
// tedivm/jshrink, also require-dev) and writes both `-debug.js` and the
// minified `.js`; RELEASE only ever asks for the unsuffixed name, so that is
// the one written here. mod_deflate compresses it on the way out.
//
// Runs from panelalpha-setup.sh on the install and upgrade stages, before
// Apache binds. Measured at well under a second per locale for all 32
// application directories.
//

require_once '/app/bootstrap.php';

Tinebase_Core::setupConfig();
Tinebase_Core::setupBuildConstants();
Tinebase_Core::setLocale('en_US');

function pa_say(string $m): void
{
    fwrite(STDERR, '[tine] ' . $m . PHP_EOL);
}

/** Every directory under /app that has a js/ -- upstream's own fileset. */
$apps = [];
foreach (scandir('/app') ?: [] as $entry) {
    if ($entry[0] === '.' || !is_dir("/app/$entry/js")) {
        continue;
    }
    $apps[] = $entry;
}
sort($apps);

// Every locale Tinebase ships a .po for. All of them, because the language is
// the user's choice in their own preferences and this recipe cannot know it --
// and because the whole set costs a couple of seconds and about 25 MB.
$locales = array_keys(Tinebase_Translation::getAvailableTranslations());

$written = 0;
$bytes = 0;
$started = microtime(true);

foreach ($locales as $locale) {
    $zendLocale = new Zend_Locale($locale);
    foreach ($apps as $app) {
        $js = Tinebase_Translation::getJsTranslations($zendLocale, $app);

        // Upstream appends the language-completeness figures to Tinebase's
        // file when langstatistics.json exists; it is produced by
        // `php langHelper.php --statistics`, which this recipe does not run,
        // and the client treats its absence as "no statistics" rather than as
        // an error (Tine.__translationData.translationStats is only read by
        // the language chooser to show a percentage).
        $target = "/app/$app/js/$app-lang-$locale.js";
        if (file_put_contents($target, $js) === false) {
            pa_say("could not write $target");
            exit(1);
        }
        $written++;
        $bytes += strlen($js);
    }
}

pa_say(sprintf(
    'built %d client translation files (%d locales x %d applications, %.1f MiB) in %.1fs',
    $written,
    count($locales),
    count($apps),
    $bytes / 1048576,
    microtime(true) - $started
));

// The one file the client cannot start without, for the one locale it will
// definitely ask for. A generator that wrote nothing would otherwise be a
// silent success and an unusable application.
$probe = '/app/Tinebase/js/Tinebase-lang-en.js';
if (!is_file($probe) || !str_contains((string) file_get_contents($probe), '__isLoaded')) {
    pa_say("$probe is missing or has no __isLoaded flag; the client would hang on startup");
    exit(1);
}

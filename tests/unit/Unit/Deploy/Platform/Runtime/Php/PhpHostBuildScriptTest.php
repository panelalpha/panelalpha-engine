<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The host build script of a PHP project, pinned for each combination of options.
 */
class PhpHostBuildScriptTest extends TestCase
{
    public static function options(): array
    {
        return [
            'every option' => [['composer install --no-dev --no-interaction --no-scripts --no-plugins', 'composer run-script post-autoload-dump', true, '8.3', true, true, true, '{"autoload":{"classmap":["lib","src\\/Legacy","vendor\\/x","a.php","..\\/up"]}}'], "echo \"[panelalpha] build: dependencies\" >&2\necho \"[panelalpha] composer plugins allowed: composer/installers, drupal/core-composer-scaffold, drupal/core-recipe-unpack, drupal/core-project-message, symfony/runtime; any other plugin is installed but not run\" >&2\ncomposer config --no-plugins platform.php 8.3.99\nfor d in 'lib' 'src/Legacy'; do [ -e \"\$d\" ] || { echo \"[panelalpha] creating autoload classmap directory missing from the checkout: \$d\" >&2; mkdir -p \"\$d\"; }; done\necho \"[panelalpha] this project's composer.lock pins a PHP its own packages reject, so the locked set is installed as-is; php is the one requirement not enforced for it\" >&2\ncomposer install --no-dev --no-interaction --no-scripts --ignore-platform-req=php\n[ -e composer.lock ] || cp .pa-runtime-composer.lock composer.lock\necho \"[panelalpha] build: assets\" >&2\nphp -r '\$m = getenv(\"COMPOSER\"); \$p = json_decode((string) file_get_contents(\"composer.json\")); \$r = json_decode((string) file_get_contents(\$m)); if (!is_object(\$r)) { exit(0); } if (!isset(\$r->config) || !is_object(\$r->config)) { \$r->config = new stdClass(); } if (is_object(\$p) && isset(\$p->config) && is_object(\$p->config) && property_exists(\$p->config, \"allow-plugins\")) { \$r->config->{\"allow-plugins\"} = \$p->config->{\"allow-plugins\"}; } else { unset(\$r->config->{\"allow-plugins\"}); } file_put_contents(\$m, json_encode(\$r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));'\ncomposer run-script post-autoload-dump"],
            'default install, nothing else' => [['', '', true], "echo \"[panelalpha] build: dependencies\" >&2\ncomposer install --no-dev --no-interaction --no-scripts --no-plugins --optimize-autoloader"],
            'no composer, only assets' => [['', 'php compile.php', false, '8.2', true, true], "echo \"[panelalpha] build: assets\" >&2\nphp -r '\$m = getenv(\"COMPOSER\"); \$p = json_decode((string) file_get_contents(\"composer.json\")); \$r = json_decode((string) file_get_contents(\$m)); if (!is_object(\$r)) { exit(0); } if (!isset(\$r->config) || !is_object(\$r->config)) { \$r->config = new stdClass(); } if (is_object(\$p) && isset(\$p->config) && is_object(\$p->config) && property_exists(\$p->config, \"allow-plugins\")) { \$r->config->{\"allow-plugins\"} = \$p->config->{\"allow-plugins\"}; } else { unset(\$r->config->{\"allow-plugins\"}); } file_put_contents(\$m, json_encode(\$r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));'\nphp compile.php"],
            'declared install, unrestricted' => [['composer install --no-plugins', '', false, null, false, false, true, null], "echo \"[panelalpha] build: dependencies\" >&2\ncomposer install --no-plugins\n[ -e composer.lock ] || cp .pa-runtime-composer.lock composer.lock"],
        ];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('options')]
    public function test_the_script_is_pinned(array $arguments, string $expected): void
    {
        $this->assertSame($expected, PhpHostBuild::script(...$arguments));
    }
}

<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\TestCase;

/**
 * Composer's autoload dump throws on a classmap path that does not exist, with
 * or without --optimize-autoloader. ILIAS lists a gitignored directory its own
 * `pre-install-cmd` creates, and `--no-scripts` skips that script.
 */
class PhpHostBuildClassmapTest extends TestCase
{
    private const ILIAS = [
        'autoload' => [
            'classmap' => [
                './public/Customizing/global/plugins',
                './components',
                './vendor/ilias',
                './components/ILIAS/setup_/classes',
            ],
        ],
        'config' => ['vendor-dir' => './vendor/composer/vendor'],
    ];

    public function test_missing_classmap_directories_are_created_before_the_install(): void
    {
        $script = PhpHostBuild::script(
            'composer install --no-dev',
            '',
            true,
            null,
            false,
            null,
            (string) json_encode(self::ILIAS)
        );

        $mkdir = strpos($script, "'public/Customizing/global/plugins'");
        $install = strpos($script, 'composer install --no-dev');
        $this->assertNotFalse($mkdir);
        $this->assertNotFalse($install);
        $this->assertLessThan($install, $mkdir, 'the directory has to exist before the autoload dump');
        // Only when absent: an existing path, file or directory, is not touched.
        $this->assertStringContainsString('[ -e "$d" ] ||', $script);
    }

    public function test_the_step_lists_every_plain_directory(): void
    {
        $step = PhpHostBuild::classmapDirsStep((string) json_encode(self::ILIAS));

        $this->assertStringStartsWith(
            "for d in 'public/Customizing/global/plugins' 'components' 'vendor/ilias' 'components/ILIAS/setup_/classes';",
            $step
        );
    }

    public function test_files_globs_traversal_and_the_vendor_dir_are_left_alone(): void
    {
        $step = PhpHostBuild::classmapDirsStep((string) json_encode([
            'autoload' => ['classmap' => [
                'lib/legacy.php',
                'src/*/Legacy',
                '../outside',
                'a/../../b',
                '/etc/cron.d',
                'vendor/acme/tool',
                'with space',
                "x'; rm -rf /; '",
            ]],
        ]));

        $this->assertSame('', $step);
    }

    public function test_no_classmap_means_no_step(): void
    {
        $this->assertSame('', PhpHostBuild::classmapDirsStep(null));
        $this->assertSame('', PhpHostBuild::classmapDirsStep('not json'));
        $this->assertSame('', PhpHostBuild::classmapDirsStep('{"autoload":{"psr-4":{"App\\\\":"src/"}}}'));
        $this->assertSame('', PhpHostBuild::classmapDirsStep('{"autoload":"src"}'));

        $script = PhpHostBuild::script('composer install --no-dev', '', true);
        $this->assertStringNotContainsString('classmap', $script);
    }

    /** No install, nothing to dump: an asset-only script gets no step. */
    public function test_an_asset_only_script_gets_no_step(): void
    {
        $script = PhpHostBuild::script('', 'php compile.php', false, null, false, null, (string) json_encode(self::ILIAS));

        $this->assertStringNotContainsString('mkdir', $script);
    }
}

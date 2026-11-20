<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Refusals of `check`, `app_root`, `docroot`, `build_args`, `commands_from`, `commands` and `env`, word for word, and an app root that is the repository.
 */
class PlatformManifestValueRefusalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
        SourceRecipes::flush();
    }

    /** @param  array<string, mixed>  $raw */
    private function manifest(array $raw): PlatformManifest
    {
        return PlatformManifest::fromArray(array_merge([
            'id' => 'probe',
            'label' => 'Probe',
            'strategy' => 'probe',
            'priority' => 0,
            'runtime' => 'node',
            'detect' => ['file' => 'x'],
        ], $raw), 'probe.yaml');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
        return [
            'check a number' => [['check' => 5], 'probe: check must be a check reference or a list of them'],
            'build_args a list value' => [['build_args' => ['NODE_ENV' => ['a']]], 'probe: build_args.NODE_ENV: must be a string'],
            'commands_from a blank resolver' => [['commands_from' => ['build' => ' ']], "probe: 'commands_from' entries must be command id => resolver name"],
            'commands_from a numeric id' => [['commands_from' => ['laravel']], "probe: 'commands_from' entries must be command id => resolver name"],
            'a command that is a string' => [['commands' => ['echo hi']], 'probe: commands[0]: must be an object'],
            'env a bad name' => [['env' => ['1X' => 'y']], "probe: '1X' is not a valid environment variable name"],
            'env a list value' => [['env' => ['X' => ['y']]], 'probe: env.X: must be a string, integer or boolean'],
        ];
    }

    /** @param  array<string, mixed>  $raw */
    #[DataProvider('refusals')]
    public function test_a_malformed_manifest_is_refused_with_its_message(array $raw, string $message): void
    {
        try {
            $this->manifest($raw);
            $this->fail('expected a ManifestException');
        } catch (ManifestException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function appRootsOutside(): array
    {
        return [
            'climbing out' => ['web/../..'],
            'a dot segment' => ['web/./api'],
            'an empty segment' => ['web//api'],
        ];
    }

    #[DataProvider('appRootsOutside')]
    public function test_an_app_root_that_is_not_a_plain_relative_path_is_refused(string $appRoot): void
    {
        try {
            $this->manifest(['app_root' => $appRoot]);
            $this->fail('expected a ManifestException');
        } catch (ManifestException $e) {
            $this->assertSame("probe: app_root must be a relative path inside the repository, got '{$appRoot}'", $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function docrootsOutside(): array
    {
        return [
            'climbing out' => ['web/../..'],
            'a dot segment' => ['web/./api'],
            'an empty segment' => ['web//api'],
        ];
    }

    #[DataProvider('docrootsOutside')]
    public function test_a_docroot_that_is_not_a_plain_relative_path_is_refused(string $docroot): void
    {
        try {
            $this->manifest(['docroot' => $docroot]);
            $this->fail('expected a ManifestException');
        } catch (ManifestException $e) {
            $this->assertSame("probe: docroot must be a relative path inside the application, got '{$docroot}'", $e->getMessage());
        }
    }

    public function test_an_app_root_of_the_repository_itself_is_empty(): void
    {
        $this->assertSame('', $this->manifest(['app_root' => '/./'])->appRoot);
        $this->assertSame('', $this->manifest(['app_root' => '.'])->appRoot);
        $this->assertSame('apps/web', $this->manifest(['app_root' => '/apps/web/'])->appRoot);
    }

    public function test_build_args_keep_integers_as_strings(): void
    {
        $this->assertSame(['PORT' => '8080', 'MODE' => 'prod'], $this->manifest(['build_args' => ['PORT' => 8080, 'MODE' => 'prod']])->buildArgs);
    }
}

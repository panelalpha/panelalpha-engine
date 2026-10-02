<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\Runtime\Php\PhpBuild;
use App\Lib\Deploy\Platform\Runtime\Php\PhpEnvironment;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Strategy\EntrypointWriter;
use App\System\Project\Dind\Strategy\PhpStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A PHP platform manifest's `env:` reaches the app container. Passbolt's
 * PASSBOLT_SECURITY_DISPLAY_NON_WEBUSER_WARNING was detected and then dropped.
 */
class PhpManifestEnvironmentTest extends TestCase
{
    private const PASSBOLT = [
        'APP_ENV' => 'production',
        'LOG_CHANNEL' => 'stderr',
        'PASSBOLT_SECURITY_DISPLAY_NON_WEBUSER_WARNING' => 'false',
    ];

    public function test_a_key_only_the_manifest_declares_is_kept(): void
    {
        $env = PhpEnvironment::withManifest(PhpEnvironment::for([], 'https://pb.test', false), self::PASSBOLT);

        $this->assertSame('false', $env['PASSBOLT_SECURITY_DISPLAY_NON_WEBUSER_WARNING']);
        $this->assertSame('https://pb.test', $env['APP_URL']);
    }

    /** The engine still decides APP_ENV: a Symfony app gets `prod` whatever the manifest says. */
    public function test_the_engine_values_outrank_the_manifest(): void
    {
        $env = PhpEnvironment::withManifest(
            PhpEnvironment::for([], null, false, true),
            ['APP_ENV' => 'production', 'PA_DOCROOT' => '/elsewhere']
        );

        $this->assertSame('prod', $env['APP_ENV']);
    }

    public function test_no_env_block_changes_nothing(): void
    {
        $engine = PhpEnvironment::for([], null, true);

        $this->assertSame($engine, PhpEnvironment::withManifest($engine, null));
        $this->assertSame($engine, PhpEnvironment::withManifest($engine, []));
    }

    public function test_it_lands_in_the_app_service_and_the_account_still_wins(): void
    {
        $decision = [
            'runtime' => 'php',
            'image' => 'php:8.3-apache',
            'env' => PhpEnvironment::withManifest(PhpEnvironment::for([], null, false), self::PASSBOLT),
        ];
        $decision = ComposeEnvironment::layer($decision, [], ['LOG_CHANNEL' => 'daily']);

        $app = Yaml::parse(DeployCompose::framework($decision, 8000))['services']['app'];

        $this->assertSame('false', $app['environment']['PASSBOLT_SECURITY_DISPLAY_NON_WEBUSER_WARNING']);
        $this->assertSame('daily', $app['environment']['LOG_CHANNEL']);
    }

    /** The strategy itself, not just the helper: the manifest's decision is what it reads. */
    public function test_the_php_strategy_passes_the_manifest_env_through(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getUid')->willReturn(null);
        $user->method('getGid')->willReturn(null);
        $user->method('getChownString')->willReturn(null);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileExists')->willReturn(false);
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $entrypoint = $this->createStub(EntrypointWriter::class);
        $entrypoint->method('deployPhaseEnvironment')->willReturn(['PA_DEPLOY_PHASE' => 'install']);
        $strategy = $this->createStub(DeployStrategy::class);
        $strategy->method('entrypoint')->willReturn($entrypoint);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);
        $dind->method('system')->willReturn($system);
        $dind->method('strategy')->willReturn($strategy);
        $dind->method('userAppDirPath')->willReturn('/home/pb/project');

        $decision = (new \ReflectionMethod(PhpStrategy::class, 'decision'))->invoke(
            new PhpStrategy($dind),
            [],
            false,
            'https://pb.test',
            false,
            false,
            new PhpBuild(composerJson: '{}', artisan: false, baseImage: 'panelalpha/php:8.3'),
            ['env' => self::PASSBOLT]
        );

        $this->assertSame('false', $decision['env']['PASSBOLT_SECURITY_DISPLAY_NON_WEBUSER_WARNING']);
        $this->assertSame('install', $decision['env']['PA_DEPLOY_PHASE']);
        $this->assertSame('https://pb.test', $decision['env']['APP_URL']);
    }
}

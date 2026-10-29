<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\EnvFile;
use App\Lib\Project\ApiAccess;
use App\Models\Setting;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\CopiedRunFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * A staging, clone or push copies the source's run file and `.env.panelalpha`,
 * which carry the source's Engine API address and token. The copy holds the
 * values its own allowance gives it, and none when it has no allowance.
 */
class CopiedApiAccessTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private LocalHostSystem $system;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://203-0-113-7.panelalpha.direct',
        ]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
        Queue::fake();
        $this->root = sys_get_temp_dir().'/pa-copied-api-access-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/home/stg/project', 0777, true);
        $this->system = new LocalHostSystem($this->root, $this->root.'/home');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_copy_without_an_allowance_keeps_none_of_the_sources_token(): void
    {
        [$live, $stg] = $this->pair();
        $liveEnv = $this->allow($live);
        $this->writeCopiedFiles($liveEnv);

        $this->runFile($stg)->repointFrom($live, $this->path(EngineArtifacts::RUN_COMPOSE));

        $environment = $this->appEnvironment();
        $this->assertSame('bar', $environment['FOO']);
        $this->assertSame([], array_intersect_key($environment, array_flip(ApiAccess::ENV_KEYS)));
        $this->assertSame(['FOO' => 'bar'], $this->envOverrides());
        $this->assertSame('600', sprintf('%o', fileperms($this->path(EngineArtifacts::ENV_OVERRIDES)) & 0777));
    }

    public function test_an_allowed_target_gets_its_own_token_in_place_of_the_sources(): void
    {
        [$live, $stg] = $this->pair();
        $liveEnv = $this->allow($live);
        $stgEnv = $this->allow($stg);
        $this->writeCopiedFiles($liveEnv);

        $this->runFile($stg)->repointFrom($live, $this->path(EngineArtifacts::RUN_COMPOSE));

        $this->assertNotSame($liveEnv[ApiAccess::ENV_TOKEN], $stgEnv[ApiAccess::ENV_TOKEN]);
        $this->assertSame($stgEnv[ApiAccess::ENV_TOKEN], $this->appEnvironment()[ApiAccess::ENV_TOKEN]);
        $this->assertSame(['FOO' => 'bar'] + $stgEnv, $this->envOverrides());
    }

    public function test_a_symlinked_env_file_is_refused(): void
    {
        [$live, $stg] = $this->pair();
        $this->writeCopiedFiles($this->allow($live));
        file_put_contents($this->root.'/elsewhere.env', "FOO=bar\n");
        unlink($this->path(EngineArtifacts::ENV_OVERRIDES));
        symlink($this->root.'/elsewhere.env', $this->path(EngineArtifacts::ENV_OVERRIDES));

        $this->expectExceptionMessage('it is a symlink');

        $this->runFile($stg)->repointFrom($live, $this->path(EngineArtifacts::RUN_COMPOSE));
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pair(): array
    {
        $live = $this->makeUser('live', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001], 'live.example.test');
        $stg = $this->makeUser('stg', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001], 'stg.example.test');

        return [$live->refresh(), $stg->refresh()];
    }

    /**
     * @return array<string, string>
     */
    private function allow(User $user): array
    {
        $access = new ApiAccess($this->system, new RecordingFirewall);
        $access->allow($user, ApiAccess::SCOPE_ALL, false);

        return $access->envFor($user);
    }

    /**
     * @param  array<string, string>  $sourceEnv
     */
    private function writeCopiedFiles(array $sourceEnv): void
    {
        file_put_contents($this->path(EngineArtifacts::RUN_COMPOSE), Yaml::dump([
            'services' => [
                GeneratedCompose::APP_SERVICE => [
                    'image' => 'acme/app',
                    'labels' => [GeneratedCompose::LABEL => 'true'],
                    'environment' => ['FOO' => 'bar'] + $sourceEnv,
                ],
            ],
        ], 6, 2));
        file_put_contents($this->path(EngineArtifacts::ENV_OVERRIDES), EnvFile::merge('', ['FOO' => 'bar'] + $sourceEnv));
        chmod($this->path(EngineArtifacts::ENV_OVERRIDES), 0600);
    }

    private function runFile(User $user): CopiedRunFile
    {
        $runtime = (new ProjectAggregate($this->system, $user))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return new CopiedRunFile($runtime);
    }

    /**
     * @return array<string, mixed>
     */
    private function appEnvironment(): array
    {
        $compose = Yaml::parseFile($this->path(EngineArtifacts::RUN_COMPOSE));

        return $compose['services'][GeneratedCompose::APP_SERVICE]['environment'];
    }

    /**
     * @return array<string, string>
     */
    private function envOverrides(): array
    {
        $vars = [];
        foreach (EnvFile::parse((string) file_get_contents($this->path(EngineArtifacts::ENV_OVERRIDES))) as $row) {
            if (($row['type'] ?? null) === 'variable') {
                $vars[$row['key']] = $row['value'];
            }
        }

        return $vars;
    }

    private function path(string $name): string
    {
        return $this->root.'/home/stg/project/'.$name;
    }
}

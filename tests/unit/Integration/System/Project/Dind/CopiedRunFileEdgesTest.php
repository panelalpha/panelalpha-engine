<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\CopiedRunFile;
use App\System\Project\Dind\Strategy\ManifestDatabase;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;
use Tests\Integration\System\Project\LocalHostSystem;

/**
 * The run file a copy took from itself has nothing to repoint, and the shapes a run file may hold
 * besides the usual ones: a service that is not a map, a volume with no target, and extra hosts as a map.
 */
class CopiedRunFileEdgesTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'env.USERS_DB_HOST' => 'localhost',
        ]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
        $this->root = sys_get_temp_dir().'/pa-copied-run-file-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/home/stg/project', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_run_file_copied_from_the_project_itself_is_left_as_it_was(): void
    {
        [, $stg] = $this->pair();
        $this->writeRunFile(['services' => ['app' => [
            'image' => 'acme/app',
            'environment' => ['APP_URL' => 'https://stg.example.test'],
            'volumes' => ['/home/stg/.cache/composer:/var/cache/pa-composer'],
        ]]]);
        $before = (string) file_get_contents($this->path());

        $this->assertFalse($this->runFile($stg)->repointFrom($stg, $this->path()));

        $this->assertSame($before, file_get_contents($this->path()));
    }

    public function test_a_service_that_is_not_a_map_and_a_volume_with_no_target_are_kept(): void
    {
        [$live, $stg] = $this->pair(liveHasDatabase: false);
        $this->writeRunFile(['services' => [
            'app' => ['image' => 'acme/app', 'volumes' => ['/data', '/home/live/.cache/composer:/cache']],
            'broken' => 'not a service',
        ]]);

        $this->assertTrue($this->runFile($stg)->repointFrom($live, $this->path()));

        $services = Yaml::parseFile($this->path())['services'];
        $this->assertSame(['/data', '/home/stg/.cache/composer:/cache'], $services['app']['volumes']);
        $this->assertSame('not a service', $services['broken']);
    }

    public function test_extra_hosts_given_as_a_map_are_repinned_by_name(): void
    {
        [$live, $stg] = $this->pair();
        $liveDb = ManifestDatabase::environment((array) AppDatabase::credentialsOf($live));
        $this->writeRunFile(['services' => [
            'app' => [
                'image' => 'acme/app',
                'environment' => $liveDb,
                'extra_hosts' => ['localhost' => TestNetwork::private(9), 'other' => TestNetwork::private(1)],
            ],
            'worker' => [
                'image' => 'acme/worker',
                'environment' => $liveDb,
                'extra_hosts' => ['other' => TestNetwork::private(1)],
            ],
        ]]);

        $this->assertTrue($this->runFile($stg)->repointFrom($live, $this->path()));

        $services = Yaml::parseFile($this->path())['services'];
        $this->assertSame(['localhost' => TestNetwork::LOOPBACK, 'other' => TestNetwork::private(1)], $services['app']['extra_hosts']);
        $this->assertSame(['other' => TestNetwork::private(1)], $services['worker']['extra_hosts']);
        $this->assertSame('stg_app', $services['app']['environment']['DB_DATABASE']);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pair(bool $liveHasDatabase = true): array
    {
        $live = $this->makeUser('live', ['template' => 'dind', 'mysql_prefix' => 'live_', 'UID' => 1001, 'GID' => 1001], 'live.example.test');
        $stg = $this->makeUser('stg', ['template' => 'dind', 'mysql_prefix' => 'stg_', 'UID' => 1002, 'GID' => 1002], 'stg.example.test');
        $this->makeMainDomain($live, 'live.example.test');
        $this->makeMainDomain($stg, 'stg.example.test');
        if ($liveHasDatabase) {
            $live->setDetails([AppDatabase::PASSWORD_DETAIL => 'live-secret']);
            $live->save();
        }
        $stg->setDetails([AppDatabase::PASSWORD_DETAIL => 'stg-secret']);
        $stg->save();

        return [$live->refresh(), $stg->refresh()];
    }

    private function runFile(User $user): CopiedRunFile
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->root, $this->root.'/home'), $user))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return new CopiedRunFile($runtime);
    }

    /** @param array<string, mixed> $compose */
    private function writeRunFile(array $compose): void
    {
        file_put_contents($this->path(), Yaml::dump($compose, 6, 2));
    }

    private function path(): string
    {
        return $this->root.'/home/stg/project/'.EngineArtifacts::RUN_COMPOSE;
    }
}

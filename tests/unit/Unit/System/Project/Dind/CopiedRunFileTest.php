<?php

namespace Tests\Unit\System\Project\Dind;

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
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * A staging, clone or push copies the run file the source's deploy wrote, with
 * the source's application database and address in it as literal values. The
 * copy points every one of them at its own.
 */
class CopiedRunFileTest extends TestCase
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

    public function test_the_copy_runs_on_its_own_database_and_answers_as_its_own_domain(): void
    {
        [$live, $stg] = $this->pair();
        $liveDb = ManifestDatabase::environment((array) AppDatabase::credentialsOf($live));
        $this->writeRunFile([
            'services' => [
                'app' => [
                    'image' => 'panelalpha/php:8.3',
                    'environment' => $liveDb + [
                        'APP_URL' => 'https://live.example.test',
                        'ASSET_URL' => 'https://live.example.test',
                        'SERVERNAME' => 'live.example.test',
                        'UPLOADS' => 'https://live.example.test/uploads',
                        'MAIL_HOST' => 'smtp.live.example.test',
                        'DB_PREFIX' => 'chyrp_',
                    ],
                    'extra_hosts' => ['localhost:10.9.9.9', 'other:10.1.1.1'],
                ],
                'worker' => [
                    'image' => 'acme/worker',
                    'environment' => ['DB_DATABASE=live_app', 'SITE=http://live.example.test', 'PLAIN'],
                ],
            ],
        ]);

        $this->assertTrue($this->runFile($stg)->repointFrom($live, $this->path()));

        $compose = Yaml::parseFile($this->path());
        $app = $compose['services']['app'];
        $this->assertSame(
            ManifestDatabase::environment((array) AppDatabase::credentialsOf(User::findByUsernameOrFail('stg'))),
            array_intersect_key($app['environment'], $liveDb)
        );
        $this->assertSame('stg_app', $app['environment']['DB_DATABASE']);
        $this->assertSame('stg-secret', $app['environment']['DB_PASSWORD']);
        $this->assertSame('https://stg.example.test', $app['environment']['APP_URL']);
        $this->assertSame('https://stg.example.test', $app['environment']['ASSET_URL']);
        $this->assertSame('stg.example.test', $app['environment']['SERVERNAME']);
        $this->assertSame('https://stg.example.test/uploads', $app['environment']['UPLOADS']);
        $this->assertSame('smtp.live.example.test', $app['environment']['MAIL_HOST']);
        $this->assertSame('chyrp_', $app['environment']['DB_PREFIX']);
        $this->assertSame(['localhost:127.0.0.1', 'other:10.1.1.1'], $app['extra_hosts']);
        $this->assertSame(
            ['DB_DATABASE=stg_app', 'SITE=https://stg.example.test', 'PLAIN'],
            $compose['services']['worker']['environment']
        );
        $this->assertSame('600', sprintf('%o', fileperms($this->path()) & 0777));
    }

    public function test_a_database_the_project_set_itself_is_left_alone(): void
    {
        [$live, $stg] = $this->pair();
        $this->writeRunFile([
            'services' => [
                'app' => [
                    'image' => 'acme/app',
                    'environment' => ['DB_DATABASE' => 'external', 'DB_PASSWORD' => 'mine'],
                ],
            ],
        ]);

        $this->assertFalse($this->runFile($stg)->repointFrom($live, $this->path()));
        $this->assertSame(
            ['DB_DATABASE' => 'external', 'DB_PASSWORD' => 'mine'],
            Yaml::parseFile($this->path())['services']['app']['environment']
        );
    }

    public function test_a_source_without_an_app_database_only_moves_the_address(): void
    {
        [$live, $stg] = $this->pair(liveHasDatabase: false);
        $this->writeRunFile([
            'services' => [
                'wordpress' => ['image' => 'wordpress', 'environment' => ['WORDPRESS_DB_HOST' => 'db', 'URL' => 'https://live.example.test']],
                'db' => ['image' => 'mariadb', 'environment' => ['MARIADB_DATABASE' => 'wordpress']],
            ],
        ]);

        $this->assertTrue($this->runFile($stg)->repointFrom($live, $this->path()));

        $compose = Yaml::parseFile($this->path());
        $this->assertSame(['WORDPRESS_DB_HOST' => 'db', 'URL' => 'https://stg.example.test'], $compose['services']['wordpress']['environment']);
        $this->assertSame(['MARIADB_DATABASE' => 'wordpress'], $compose['services']['db']['environment']);
    }

    public function test_the_copy_runs_as_its_own_account(): void
    {
        [$live, $stg] = $this->pair(liveHasDatabase: false);
        $this->writeRunFile([
            'services' => [
                'app' => ['image' => 'panelalpha/php:8.3', 'user' => '1001:1001'],
                'worker' => ['image' => 'acme/worker', 'user' => '1001'],
                'db' => ['image' => 'mariadb', 'user' => 'mysql'],
            ],
        ]);
        $dir = dirname($this->path()).'/';
        file_put_contents($dir.EngineArtifacts::RUN_PASSWD, "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33::/var/www:/usr/sbin/nologin\napp:x:1001:1001:app:/app:/usr/sbin/nologin\n");
        file_put_contents($dir.EngineArtifacts::RUN_GROUP, "root:x:0:\napp:x:1001:\n");

        $this->assertTrue($this->runFile($stg)->repointFrom($live, $this->path()));

        $services = Yaml::parseFile($this->path())['services'];
        $this->assertSame('1002:1002', $services['app']['user']);
        $this->assertSame('1002', $services['worker']['user']);
        $this->assertSame('mysql', $services['db']['user']);
        $this->assertSame(
            "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33::/var/www:/usr/sbin/nologin\napp:x:1002:1002:app:/app:/usr/sbin/nologin\n",
            file_get_contents($dir.EngineArtifacts::RUN_PASSWD)
        );
        $this->assertSame("root:x:0:\napp:x:1002:\n", file_get_contents($dir.EngineArtifacts::RUN_GROUP));
        $this->assertSame('644', sprintf('%o', fileperms($dir.EngineArtifacts::RUN_PASSWD) & 0777));
    }

    public function test_account_files_without_the_sources_entry_are_left_alone(): void
    {
        [$live, $stg] = $this->pair(liveHasDatabase: false);
        $this->writeRunFile(['services' => ['app' => ['image' => 'acme/app']]]);
        $passwd = "root:x:0:0:root:/root:/bin/bash\napp:x:1003:1003:app:/app:/usr/sbin/nologin\n";
        file_put_contents(dirname($this->path()).'/'.EngineArtifacts::RUN_PASSWD, $passwd);

        $this->runFile($stg)->repointFrom($live, $this->path());

        $this->assertSame($passwd, file_get_contents(dirname($this->path()).'/'.EngineArtifacts::RUN_PASSWD));
    }

    public function test_a_symlinked_run_file_is_refused(): void
    {
        [$live, $stg] = $this->pair();
        file_put_contents($this->root.'/elsewhere.yml', "services: {}\n");
        symlink($this->root.'/elsewhere.yml', $this->path());

        $this->expectExceptionMessage('it is a symlink');

        $this->runFile($stg)->repointFrom($live, $this->path());
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

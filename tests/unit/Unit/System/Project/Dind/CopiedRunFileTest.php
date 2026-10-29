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
use Tests\Support\TestNetwork;
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
                    'extra_hosts' => ['localhost:'.TestNetwork::private(9), 'other:'.TestNetwork::private(1)],
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
        $this->assertSame(['localhost:'.TestNetwork::LOOPBACK, 'other:'.TestNetwork::private(1)], $app['extra_hosts']);
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

    public function test_the_copy_mounts_its_own_home_not_the_sources(): void
    {
        [$live, $stg] = $this->pair(liveHasDatabase: false);
        $this->writeRunFile([
            'services' => [
                'app' => [
                    'image' => 'panelalpha/php:8.3',
                    'volumes' => [
                        '/home/live/.cache/composer:/var/cache/pa-composer',
                        '/home/livestock/data:/data:ro',
                        'appdata:/var/lib/app',
                        './uploads:/app/uploads',
                    ],
                ],
                'worker' => [
                    'image' => 'acme/worker',
                    'volumes' => [
                        ['type' => 'bind', 'source' => '/home/live/.cache/composer', 'target' => '/cache'],
                        ['type' => 'volume', 'source' => '/home/live', 'target' => '/named'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($this->runFile($stg)->repointFrom($live, $this->path()));

        $services = Yaml::parseFile($this->path())['services'];
        $this->assertSame([
            '/home/stg/.cache/composer:/var/cache/pa-composer',
            '/home/livestock/data:/data:ro',
            'appdata:/var/lib/app',
            './uploads:/app/uploads',
        ], $services['app']['volumes']);
        $this->assertSame('/home/stg/.cache/composer', $services['worker']['volumes'][0]['source']);
        $this->assertSame('/home/live', $services['worker']['volumes'][1]['source']);
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

    public function test_the_env_files_beside_the_run_file_get_the_copys_database_and_address(): void
    {
        [$live, $stg] = $this->pair();
        $this->writeRunFile(['services' => ['app' => ['image' => 'acme/app']]]);
        $this->writeEnvFile('.env', "# app\nAPP_URL=https://live.example.test\nNAME=\"My App\"\nDB_DATABASE=live_app\nDB_PASSWORD=live-secret\nMAIL_HOST=smtp.live.example.test\n", 0644);
        $this->writeEnvFile('.env.default', "APP_URL=https://live.example.test/shop\nDB_DATABASE=external\n", 0600);
        $this->writeEnvFile(EngineArtifacts::ENV_OVERRIDES, "SERVER_NAME='live.example.test'\n", 0600);

        $this->runFile($stg)->repointFrom($live, $this->path());

        $this->assertSame(
            "# app\nAPP_URL=https://stg.example.test\nNAME=\"My App\"\nDB_DATABASE=stg_app\nDB_PASSWORD=stg-secret\nMAIL_HOST=smtp.live.example.test\n",
            $this->envFile('.env')
        );
        $this->assertSame("APP_URL=https://stg.example.test/shop\nDB_DATABASE=external\n", $this->envFile('.env.default'));
        $this->assertSame("SERVER_NAME=stg.example.test\n", $this->envFile(EngineArtifacts::ENV_OVERRIDES));
        $this->assertSame('644', $this->envFileMode('.env'));
        $this->assertSame('600', $this->envFileMode('.env.default'));
        $this->assertSame('600', $this->envFileMode(EngineArtifacts::ENV_OVERRIDES));
    }

    public function test_a_pushed_env_file_keeps_every_line_it_does_not_repoint(): void
    {
        [$live, $stg] = $this->pair();
        $this->writeRunFile(['services' => ['app' => ['image' => 'acme/app']]]);
        $this->writeEnvFile('.env', "# keys\r\n"
            ."export APP_URL=https://live.example.test # public\r\n"
            ."JWT_KEY=\"-----BEGIN KEY-----\r\nDB_DATABASE=live_app\r\n-----END KEY-----\"\r\n"
            ."DB_DATABASE = 'live_app' # app db\r\n", 0600);

        $this->runFile($stg)->repointFrom($live, $this->path());

        $this->assertSame(
            "# keys\r\n"
            ."export APP_URL=https://stg.example.test # public\r\n"
            ."JWT_KEY=\"-----BEGIN KEY-----\r\nDB_DATABASE=live_app\r\n-----END KEY-----\"\r\n"
            ."DB_DATABASE = 'stg_app' # app db\r\n",
            $this->envFile('.env')
        );
    }

    public function test_an_env_file_without_the_sources_values_is_left_as_it_was(): void
    {
        [$live, $stg] = $this->pair();
        $this->writeRunFile(['services' => ['app' => ['image' => 'acme/app']]]);
        $contents = "KEY = 'spaced'\nexport OTHER=\"x\"\n";
        $this->writeEnvFile('.env', $contents, 0640);

        $this->runFile($stg)->repointFrom($live, $this->path());

        $this->assertSame($contents, $this->envFile('.env'));
        $this->assertSame('640', $this->envFileMode('.env'));
    }

    public function test_a_symlinked_env_file_is_left_alone(): void
    {
        [$live, $stg] = $this->pair();
        $this->writeRunFile(['services' => ['app' => ['image' => 'acme/app']]]);
        file_put_contents($this->root.'/elsewhere.env', "APP_URL=https://live.example.test\n");
        symlink($this->root.'/elsewhere.env', dirname($this->path()).'/.env');

        $this->runFile($stg)->repointFrom($live, $this->path());

        $this->assertTrue(is_link(dirname($this->path()).'/.env'));
        $this->assertSame("APP_URL=https://live.example.test\n", file_get_contents($this->root.'/elsewhere.env'));
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

    private function writeEnvFile(string $name, string $contents, int $mode): void
    {
        $path = dirname($this->path()).'/'.$name;
        file_put_contents($path, $contents);
        chmod($path, $mode);
    }

    private function envFile(string $name): string
    {
        return (string) file_get_contents(dirname($this->path()).'/'.$name);
    }

    private function envFileMode(string $name): string
    {
        return sprintf('%o', fileperms(dirname($this->path()).'/'.$name) & 0777);
    }

    private function path(): string
    {
        return $this->root.'/home/stg/project/'.EngineArtifacts::RUN_COMPOSE;
    }
}

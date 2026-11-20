<?php

namespace Tests\Integration\System;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind\CopyVolumes;
use App\System\Projects;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A push swaps the target's home for the source's, and the Engine's own
 * per-domain PHP settings file is inside it: each side keeps its own.
 */
class ProjectsPushPhpDirectivesTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir().'/pa-push-directives-'.bin2hex(random_bytes(6));
        $this->stagingPair();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_files_push_keeps_the_live_sites_own_directives(): void
    {
        $this->writeFile('live', 'live.example.test/public_html/index.php', 'live site');
        $this->writeFile('stg', 'stg.example.test/public_html/index.php', 'staging site');
        $this->writeFile('live', 'live.example.test/public_html/.user.ini', "memory_limit=300M\n");
        $this->writeFile('stg', 'stg.example.test/public_html/.user.ini', "memory_limit=128M\n");

        $this->push();

        $this->assertSame('staging site', file_get_contents($this->home('live').'/live.example.test/public_html/index.php'), 'the pushed files arrive');
        $this->assertSame("memory_limit=300M\n", $this->userIniContents('live'));
        $this->assertSame("memory_limit=128M\n", $this->userIniContents('stg'), 'the pushed side keeps its own set');
    }

    public function test_a_files_push_does_not_bring_the_staging_directives_to_a_live_site_without_them(): void
    {
        $this->writeFile('live', 'live.example.test/public_html/index.php', 'live site');
        $this->writeFile('stg', 'stg.example.test/public_html/index.php', 'staging site');
        $this->writeFile('stg', 'stg.example.test/public_html/.user.ini', "memory_limit=128M\n");

        $this->push();

        $this->assertSame('staging site', file_get_contents($this->home('live').'/live.example.test/public_html/index.php'));
        $this->assertFileDoesNotExist($this->home('live').'/live.example.test/public_html/.user.ini');
        $this->assertSame("memory_limit=128M\n", $this->userIniContents('stg'));
    }

    public function test_a_files_push_keeps_the_directives_of_every_domain_of_the_target(): void
    {
        foreach (['live', 'stg'] as $username) {
            $this->writeFile($username, "{$username}.example.test/public_html/index.php", "{$username} site");
            $this->writeFile($username, "{$username}.example.test/public_html/.user.ini", "memory_limit=1M\n");
            $this->writeFile($username, 'shared/public_html/index.php', "{$username} second site");
            $this->writeFile($username, 'shared/public_html/.user.ini', "upload_max_filesize=1M\n");
        }
        file_put_contents($this->home('live').'/live.example.test/public_html/.user.ini', "memory_limit=300M\n");
        file_put_contents($this->home('live').'/shared/public_html/.user.ini', "upload_max_filesize=64M\n");

        $this->push();

        $this->assertSame("memory_limit=300M\n", $this->userIniContents('live'));
        $this->assertSame("upload_max_filesize=64M\n", file_get_contents($this->home('live').'/shared/public_html/.user.ini'));
    }

    private function push(): void
    {
        (new Projects($this->system()))->pushToApp('stg', 'live', databases: false);
    }

    private function stagingPair(): void
    {
        $live = $this->makeUser('live', ['UID' => 2001, 'GID' => 2002, 'mysql_prefix' => 'live_']);
        $this->makeMainDomain($live, 'live.example.test');
        $this->makeAddonDomain($live, 'shared.example.test', '/shared/public_html');
        $stg = $this->makeUser('stg', ['UID' => 2003, 'GID' => 2004, 'mysql_prefix' => 'stg_']);
        $this->makeMainDomain($stg, 'stg.example.test');
        $stg->staging = $live->id;
        $stg->save();
    }

    private function makeAddonDomain(User $user, string $domain, string $documentRoot): void
    {
        Domain::make([
            'user_id' => $user->id,
            'domain' => $domain,
            'type' => 'addon',
            'details' => ['document_root' => $documentRoot],
        ])->save();
    }

    private function writeFile(string $username, string $entry, string $contents): void
    {
        $path = $this->home($username).'/'.$entry;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function home(string $username): string
    {
        return "{$this->root}/{$username}";
    }

    private function userIniContents(string $username): string
    {
        return file_get_contents($this->home($username).'/'.$username.'.example.test/public_html/.user.ini');
    }

    private function system(): System
    {
        return new class ($this->root) extends System
        {
            public function __construct(private string $root) {}

            public function pushRollbacksDirPath(): string
            {
                return $this->root.'/rollbacks';
            }

            public function project(User $model): Project
            {
                return new class ($this, $model, $this->root.'/'.$model->username) extends Project
                {
                    public function __construct(System $system, User $model, private string $home)
                    {
                        parent::__construct($system, $model);
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function copyVolumesForClone(): ?CopyVolumes
                    {
                        return null;
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function fixPermissions(): void {}

                    public function handStagedHomeToAccount(string $stagedHome): void {}

                    public function start(): void {}
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                if (LocalRoot::isNative($argv)) {
                    return $this->finished(LocalRoot::argv($argv));
                }
                $argv = LocalRoot::plain($argv);

                return match ($argv[0] ?? null) {
                    'du' => $this->finished(['printf', "1\t%s\n", end($argv)]),
                    'df' => $this->finished(['printf', "Filesystem 1K-blocks Used Available Use%% Mounted\nfs 1 1 99999999 1%% /\n"]),
                    'rsync' => $this->finished(['cp', '-a', $argv[count($argv) - 2].'.', end($argv)]),
                    default => $this->finished($argv),
                };
            }

            /**
             * @param  list<string>  $argv
             */
            private function finished(array $argv): Process
            {
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }
}

<?php

namespace Tests\Integration\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Services\Webserver;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * Pins how each PHP version's www.conf pool file is rewritten with the account's settings.
 */
class FpmPoolSettingsCharacterisationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-fpm-pool-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/templates/user/default/project/php', 0777, true);
        file_put_contents($this->root.'/templates/user/default/project/php/versions-available', "8.2\n7.4\n");
        $this->setWebserver('nginx');
    }

    protected function tearDown(): void
    {
        $this->setWebserver(null);
        exec('rm -rf '.escapeshellarg($this->root));
        Mockery::close();
        parent::tearDown();
    }

    public function test_each_version_gets_its_settings_replaced_uncommented_or_appended(): void
    {
        $host = $this->host();

        $this->apply($host, ['pm' => 'ondemand', 'pm.max_children' => '7']);

        $dir = $host->projectDirPath('alice');
        $loopback = TestNetwork::LOOPBACK;
        $this->assertSame([
            'exec '.implode(' ', Privileged::files()->read("{$dir}/php/8.2/fpm/pool.d/www.conf")),
            "cp {$dir}/php/8.2/fpm/pool.d/www.conf\n[www]\npm = ondemand\npm.max_children = 7\nlisten = {$loopback}:9082",
            'exec '.implode(' ', Privileged::files()->read("{$dir}/php/7.4/fpm/pool.d/www.conf")),
            "cp {$dir}/php/7.4/fpm/pool.d/www.conf\n[www]\npm = ondemand\npm.max_children = 7\nlisten = {$loopback}:9082",
        ], $host->journal);
    }

    public function test_behind_nginx_proxy_each_version_listens_on_its_socket(): void
    {
        $this->setWebserver('nginx-proxy');
        $host = $this->host();

        $this->apply($host, ['pm' => 'static']);

        $dir = $host->projectDirPath('alice');
        $this->assertSame([
            'exec '.implode(' ', Privileged::files()->read("{$dir}/php/8.2/fpm/pool.d/www.conf")),
            "cp {$dir}/php/8.2/fpm/pool.d/www.conf\n[www]\npm = static\n;pm.max_children = 5\nlisten = /run/php/php8.2-fpm.sock",
            'exec '.implode(' ', Privileged::files()->read("{$dir}/php/7.4/fpm/pool.d/www.conf")),
            "cp {$dir}/php/7.4/fpm/pool.d/www.conf\n[www]\npm = static\n;pm.max_children = 5\nlisten = /run/php/php7.4-fpm.sock",
        ], $host->journal);
    }

    public function test_a_copy_that_fails_names_the_file_and_the_error(): void
    {
        $host = $this->host();
        $host->copyAnswer = FakeProcess::of(1, 'out', '');

        try {
            $this->apply($host, ['pm' => 'static']);
            $this->fail('the failed copy was ignored');
        } catch (\Exception $e) {
            $this->assertSame("Could not update '{$host->projectDirPath('alice')}/php/8.2/fpm/pool.d/www.conf': out", $e->getMessage());
        }
        $this->assertCount(2, $host->journal);
    }

    public function test_a_pool_file_that_cannot_be_staged_is_not_copied_over_www_conf(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes a 0400 file, so staging never fails');
        }
        $host = $this->host();
        $umask = umask(0277);
        try {
            $this->apply($host, ['pm' => 'static']);
            $this->fail('the unwritten pool file was copied');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Could not write a file in ', $e->getMessage());
        } finally {
            umask($umask);
        }
        $this->assertCount(1, $host->journal);
    }

    /** @param array<string, string> $settings */
    private function apply(PoolHost $host, array $settings): void
    {
        $model = Mockery::mock(ModelsUser::class)->makePartial();
        $model->username = 'alice';
        $model->shouldReceive('getPhpFpmPoolSettings')->andReturn($settings);

        (new FpmStack($host, $model))->applyPhpFpmSettings(new PhpHosting(new ProjectAggregate($host, $model)));
    }

    private function host(): PoolHost
    {
        return new PoolHost($this->root, "[www]\npm = dynamic\n;pm.max_children = 5\nlisten = ".TestNetwork::LOOPBACK.":9082");
    }

    private function setWebserver(?string $slug): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, $slug);
    }
}

final class PoolHost extends System
{
    /** @var list<string> */
    public array $journal = [];

    public ?Process $copyAnswer = null;

    public function __construct(private string $root, private string $conf) {}

    public function engineDirPath(): string
    {
        return $this->root;
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->journal[] = 'exec '.(is_array($cmd) ? implode(' ', $cmd) : $cmd);

        return $this->conf;
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        if (is_array($cmd) && LocalRoot::action($cmd) === 'files.copy') {
            [$source, $target] = LocalRoot::values($cmd);
            $this->journal[] = "cp {$target}\n".file_get_contents($source);

            return $this->copyAnswer ?? FakeProcess::ok();
        }
        $this->journal[] = 'run '.(is_array($cmd) ? implode(' ', $cmd) : $cmd);

        return FakeProcess::ok();
    }
}

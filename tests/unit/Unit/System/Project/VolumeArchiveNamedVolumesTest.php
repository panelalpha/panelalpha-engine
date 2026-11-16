<?php

namespace Tests\Unit\System\Project;

use App\Exceptions\DockerErrorException;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/** A backup's volumes: the compose config's named volumes, each by the name the account's daemon has it under. */
class VolumeArchiveNamedVolumesTest extends TestCase
{
    private const CONFIG = '{"services":{"db":{"volumes":[{"type":"volume","source":"dbdata","target":"/var/lib/mysql"}]}},'
        .'"volumes":{"dbdata":{"name":"shop_dbdata"}}}';

    /** @var list<array{0: string, 1: list<string>}> */
    private array $asked = [];

    public function test_each_volume_is_named_as_the_daemon_has_it(): void
    {
        $archive = new VolumeArchive($this->standInDind('[{"Name":"shop_dbdata","Driver":"local"}]'));

        $this->assertSame([['name' => 'dbdata', 'docker_name' => 'shop_dbdata']], $archive->namedVolumes());
        $this->assertSame(['account-docker.volumes', ['test', 'shop_dbdata']], $this->asked[1]);
    }

    public function test_a_volume_the_daemon_does_not_have_fails_with_the_daemon_s_own_error(): void
    {
        $archive = new VolumeArchive($this->standInDind('[null]'));

        $this->expectException(DockerErrorException::class);
        $this->expectExceptionMessage('Error response from daemon: get shop_dbdata: no such volume');

        $archive->namedVolumes();
    }

    private function standInDind(string $volumes): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (array $argv) use ($volumes): string {
            $action = (string) LocalRoot::action($argv);
            $this->asked[] = [$action, LocalRoot::values($argv)];

            return $action === 'account-docker.volumes' ? $volumes : self::CONFIG;
        });

        return new class($system) extends Dind
        {
            public function __construct(private System $host) {}

            public function system(): System
            {
                return $this->host;
            }

            public function username(): string
            {
                return 'test';
            }

            public function homeDirPath(): string
            {
                return '/home/test';
            }

            public function userAppComposeCommand(array $rest): array
            {
                return ['env', 'PWD=/home/test/project', 'docker', 'compose', '--project-directory', '/home/test/project', ...$rest];
            }
        };
    }
}

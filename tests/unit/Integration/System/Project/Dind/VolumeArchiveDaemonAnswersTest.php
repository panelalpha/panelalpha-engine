<?php

namespace Tests\Integration\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\VolumeArchive;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * The account daemon's answers a volume backup acts on: a volume that is already there, a compose config that is
 * not JSON, and an inspect without the volume's name.
 */
class VolumeArchiveDaemonAnswersTest extends TestCase
{
    /** @var list<string> */
    private array $asked = [];

    /** @var array<string, string|\Throwable> request action => its answer */
    private array $answers = [];

    public function test_a_volume_that_already_exists_is_ensured(): void
    {
        $this->answers['account-docker.createVolume'] = new \RuntimeException('Error response from daemon: volume project_db already exists');

        $this->archive()->ensureVolume('project_db');

        $this->assertSame(['account-docker.createVolume acme project_db'], $this->asked);
    }

    public function test_any_other_failure_to_create_a_volume_is_thrown(): void
    {
        $this->answers['account-docker.createVolume'] = new \RuntimeException('no space left on device');

        $this->expectExceptionMessage('no space left on device');

        $this->archive()->ensureVolume('project_db');
    }

    public function test_a_volume_name_docker_would_refuse_is_never_asked_for(): void
    {
        try {
            $this->archive()->ensureVolume('../etc');
            $this->fail('the name is refused');
        } catch (\InvalidArgumentException|\RuntimeException) {
        }

        $this->assertSame([], $this->asked);
    }

    public function test_a_compose_config_that_is_not_json_fails_the_listing(): void
    {
        $this->answers['compose-config'] = 'no configuration file provided: not found';

        $this->expectExceptionMessage('Failed to parse inner compose config JSON');

        $this->archive()->namedVolumes();
    }

    public function test_an_inspect_without_the_volume_s_name_fails_the_listing(): void
    {
        $this->answers['compose-config'] = (string) json_encode([
            'services' => ['db' => ['volumes' => [['type' => 'volume', 'source' => 'dbdata', 'target' => '/var/lib/mysql']]]],
            'volumes' => ['dbdata' => ['name' => 'project_dbdata']],
        ]);
        $this->answers['account-docker.volumes'] = (string) json_encode([['Name' => '', 'Driver' => 'local']]);

        $this->expectExceptionMessage('Docker volume inspect returned empty name for: project_dbdata');

        $this->archive()->namedVolumes();
    }

    private function archive(): VolumeArchive
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $argv = array_values((array) $cmd);
            $action = LocalRoot::action($argv);
            $key = $action ?? 'none';
            if (! str_starts_with((string) $action, 'account-docker.')) {
                $key = 'compose-config';
            } else {
                $this->asked[] = trim($action.' '.implode(' ', LocalRoot::values($argv)));
            }
            $answer = $this->answers[$key] ?? '';
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn('acme');
        $dind->method('homeDirPath')->willReturn('/home/acme');
        $dind->method('composeFilePath')->willReturn('/opt/users/acme/docker-compose.yml');
        $dind->method('system')->willReturn($system);
        $dind->method('userAppComposeCommand')->willReturnCallback(static fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return new VolumeArchive($dind);
    }
}

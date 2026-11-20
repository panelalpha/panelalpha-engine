<?php

namespace Tests\Integration\System\Project\Dind\Generation;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\ServingImages;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * After a redeploy the names point back at what still runs, and a rollback points every name at the
 * image noted before the build; either lets the holding tags go once done.
 */
class ServingImagesSettleTest extends TestCase
{
    private const APP = 'sha256:2e3a4abc0123456789';

    private const DB = 'sha256:9f00aa11bb22cc33dd';

    private string $username = '';

    /** @var list<string> */
    private array $asked = [];

    /** @var array<string, \Closure(list<string>): string> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'settle-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_an_account_running_nothing_notes_nothing(): void
    {
        $this->answers['account-docker.listContainers'] = static fn (): string => '[]';
        $serving = new ServingImages($this->dind());

        $serving->remember();

        $this->assertSame([], $serving->noted());
        $this->assertFalse($serving->stillHeld());
        $this->assertNull($serving->restoreNames());
        $this->assertNull($serving->settle());
        $this->assertCount(1, $this->asked);
        $this->assertStringStartsWith('account-docker.listContainers ', $this->asked[0]);
    }

    public function test_every_running_container_is_held_and_noted(): void
    {
        $this->answers['account-docker.listContainers'] = static fn (): string => '[{"Id":"c0ffee","Names":["/project-app-1"]},{"Id":"beef","Names":["/project-db-1"]}]';
        $this->answers['account-docker.containers'] = static fn (): string => '[' . self::inspect('c0ffee', self::APP, 'project-app') . ',' . self::inspect('beef', self::DB, 'postgres:16') . ']';
        $logger = DeployLogger::start($this->username);

        (new ServingImages($this->dind()))->remember();

        $this->assertSame(['project-app', 'postgres:16'], array_column((new ServingImages($this->dind()))->noted(), 'ref'));
        $this->assertContains('account-docker.tag ' . self::APP . ' panelalpha-serving:2e3a4abc0123', $this->asked);
        $this->assertContains('account-docker.tag ' . self::DB . ' panelalpha-serving:9f00aa11bb22', $this->asked);
        $this->assertSame([], array_values(array_filter(
            $logger->entries(),
            static fn (array $entry): bool => str_contains($entry['msg'], 'Could not keep the images')
        )));
    }

    public function test_settling_points_back_only_the_names_whose_container_still_runs(): void
    {
        $this->note();
        $this->answers['account-docker.containers'] = static fn (): string => '['
            . json_encode(['Id' => 'c0ffee', 'Image' => self::APP, 'State' => ['Running' => true]]) . ','
            . json_encode(['Id' => 'beef', 'Image' => self::DB, 'State' => ['Running' => false]]) . ']';

        $this->assertSame(1, (new ServingImages($this->dind()))->settle());

        $this->assertSame([
            'account-docker.containers c0ffee beef',
            'account-docker.tag panelalpha-serving:2e3a4abc0123 project-app',
            'account-docker.removeImages  panelalpha-serving:2e3a4abc0123 panelalpha-serving:9f00aa11bb22',
        ], $this->asked);
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::IMAGES));
    }

    public function test_a_holding_tag_that_will_not_go_does_not_stop_the_settle(): void
    {
        $this->note();
        $this->answers['account-docker.containers'] = static fn (): string => '[null,null]';
        $this->answers['account-docker.removeImages'] = static fn (): string => throw new DockerErrorException('image is being used by a container');

        $this->assertSame(0, (new ServingImages($this->dind()))->settle());
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::IMAGES));
    }

    public function test_an_account_that_cannot_be_asked_keeps_what_was_noted(): void
    {
        $this->note();
        $this->answers['account-docker.containers'] = fn (): string => throw new DockerErrorException("container {$this->username} is not running");

        $this->assertNull((new ServingImages($this->dind()))->settle());
        $this->assertCount(2, (new ServingImages($this->dind()))->noted());
    }

    public function test_the_noted_images_are_still_held_only_when_every_tag_names_them(): void
    {
        $this->note();
        $this->answers['account-docker.images'] = static fn (): string => '[{"Id":"' . self::APP . '"},{"Id":"' . self::DB . '"}]';
        $this->assertTrue((new ServingImages($this->dind()))->stillHeld());

        $this->answers['account-docker.images'] = static fn (): string => '[{"Id":"' . self::APP . '"},null]';
        $this->assertFalse((new ServingImages($this->dind()))->stillHeld());

        $this->answers['account-docker.images'] = static fn (): string => throw new \RuntimeException('daemon gone');
        $this->assertFalse((new ServingImages($this->dind()))->stillHeld());
    }

    public function test_restoring_points_every_name_back_and_lets_the_holding_tags_go(): void
    {
        $this->note();

        $this->assertSame(2, (new ServingImages($this->dind()))->restoreNames());

        $this->assertSame([
            'account-docker.tag panelalpha-serving:2e3a4abc0123 project-app',
            'account-docker.tag panelalpha-serving:9f00aa11bb22 postgres:16',
            'account-docker.removeImages  panelalpha-serving:2e3a4abc0123 panelalpha-serving:9f00aa11bb22',
        ], $this->asked);
        $this->assertSame([], (new ServingImages($this->dind()))->noted());
    }

    public function test_a_name_the_daemon_will_not_take_is_skipped_when_restoring(): void
    {
        $this->note([['id' => 'c0ffee', 'image' => self::APP, 'ref' => 'project-app'], ['id' => 'beef', 'image' => self::DB, 'ref' => 'Not A Valid Ref!']]);

        $this->assertSame(1, (new ServingImages($this->dind()))->restoreNames());
    }

    public function test_a_restore_the_account_cannot_answer_keeps_what_was_noted(): void
    {
        $this->note();
        $this->answers['account-docker.tag'] = fn (): string => throw new DockerErrorException("container {$this->username} is not running");

        $this->assertNull((new ServingImages($this->dind()))->restoreNames());
        $this->assertCount(2, (new ServingImages($this->dind()))->noted());
    }

    /** @param ?list<array{id: string, image: string, ref: string}> $containers */
    private function note(?array $containers = null): void
    {
        (new GenerationState($this->username))->put(GenerationState::IMAGES, ['containers' => $containers ?? [
            ['id' => 'c0ffee', 'image' => self::APP, 'ref' => 'project-app'],
            ['id' => 'beef', 'image' => self::DB, 'ref' => 'postgres:16'],
            ['id' => 'broken'],
        ]]);
    }

    private static function inspect(string $id, string $image, string $ref): string
    {
        return (string) json_encode(['Id' => $id, 'Image' => $image, 'Config' => ['Image' => $ref]]);
    }

    private function dind(): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $cmd = (array) $cmd;
            $action = (string) LocalRoot::action($cmd);
            $values = array_slice(LocalRoot::values($cmd), 1);
            $this->asked[] = trim($action . ' ' . implode(' ', $values));

            return isset($this->answers[$action]) ? ($this->answers[$action])($values) : '';
        });

        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('username')->willReturn($this->username);
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('userAppComposeProjectName')->willReturn('project');

        return $dind;
    }
}

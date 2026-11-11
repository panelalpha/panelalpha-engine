<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** plan() replacing the app in place before anything starts beside it, and the line the deploy log gets. */
class ZeroDowntimePlanInPlaceTest extends TestCase
{
    private string $username = '';

    private ?DeployLogger $logger = null;

    private string $config = '';

    private string $runningApp = "old1\n";

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'plan-'.bin2hex(random_bytes(6));
        $this->config = (string) json_encode([
            'name' => 'project',
            'services' => ['app' => [
                'build' => ['context' => '/home/u/project'],
                'ports' => [['mode' => 'ingress', 'target' => 3000, 'published' => '3000', 'protocol' => 'tcp']],
            ]],
        ]);
        $this->logger = DeployLogger::start($this->username);
        $this->logger->stage(DeployLogger::STAGE_RUNNING);
    }

    protected function tearDown(): void
    {
        $this->logger = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_a_compose_file_that_cannot_be_read_is_replaced_in_place(): void
    {
        $this->config = 'not json';

        $this->assertNull(ZeroDowntimeRedeploy::plan($this->project(3000)));

        $this->assertContains('[info] Replacing the running app in place, not beside it: its compose file could not be read', $this->lines());
        $this->assertNotRan('project-next');
    }

    public function test_a_service_that_is_not_running_is_replaced_in_place(): void
    {
        $this->runningApp = '';

        $this->assertNull(ZeroDowntimeRedeploy::plan($this->project(3000)));

        $this->assertContains(
            '[info] Replacing the running app in place, not beside it: app is not running, so there is nothing to keep serving',
            $this->lines()
        );
        $this->assertNotRan('project-next');
    }

    public function test_an_account_with_no_app_port_is_replaced_in_place_without_a_word(): void
    {
        $this->assertNull(ZeroDowntimeRedeploy::plan($this->project(null)));

        $this->assertSame([], array_values(array_filter($this->lines(), static fn (string $line): bool => str_contains($line, 'in place'))));
        $this->assertNotRan("'config' '--format' 'json'");
    }

    private function project(?int $appPort): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->account(is_array($cmd) ? $cmd : [$cmd]));

        $user = $this->createStub(User::class);
        $user->method('getAppPort')->willReturn($appPort);
        $user->method('getDetails')->willReturn([]);
        $user->method('getDeployStrategy')->willReturn('dockerfile');
        $user->method('getMainDomain')->willReturn(null);
        $user->method('getChownString')->willReturn('1000:1000');

        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($user);
        $project->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $project->method('homeDirPath')->willReturn('/home/u');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('userAppComposeCommand')->willReturnCallback(fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('accountDaemon')->willReturn(new AccountDaemon($project));
        $project->method('userAppComposeProjectName')->willReturn('project');

        return $project;
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = LocalRoot::composeLine($argv) ?: implode(' ', $argv);
        $this->ran[] = $line;

        return match (true) {
            str_contains($line, "'config' '--format' 'json'") => $this->config,
            preg_match('/account-docker\.listContainers .*label com\.docker\.compose\.project=project(?: |$)/', $line) === 1 && str_contains($line, 'label com.docker.compose.service=app') => self::listed($this->runningApp),
            preg_match('/account-docker\.listContainers .*label com\.docker\.compose\.project=project(?: |$)/', $line) === 1 => self::listed("old1\n"),
            default => '',
        };
    }

    /** The daemon's listing of the containers whose ids $ids holds, one a line. */
    private static function listed(string $ids): string
    {
        return (string) json_encode(array_map(
            static fn (string $id): array => ['Id' => $id, 'Names' => ["/{$id}"]],
            preg_split('/\s+/', trim($ids), -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));
    }

    /** @return list<string> the deploy log, as "[level] message" */
    private function lines(): array
    {
        return array_map(
            static fn (array $line): string => "[{$line['level']}] {$line['msg']}",
            DeployLogger::forDeploy($this->username, $this->logger->getDeployId())->tail(500)
        );
    }

    private function assertNotRan(string $needle): void
    {
        $this->assertEmpty(array_filter($this->ran, static fn (string $line): bool => str_contains($line, $needle)), "did not run: {$needle}");
    }
}

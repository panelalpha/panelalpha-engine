<?php

namespace Tests\Unit\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\CreateInspection;
use App\Lib\Project\ProjectCreator;
use App\Models\User;
use Tests\TestCase;

/**
 * The verdict a create reports on its repository, and how it reads in the
 * response, the CLI and the deploy log.
 */
class CreateInspectionTest extends TestCase
{
    /**
     * @param array<string, mixed> $application
     * @param list<array<string, mixed>> $packages
     * @return array<string, mixed>
     */
    private static function report(array $application, array $packages = []): array
    {
        return [
            'application' => $application + ['deployable' => true, 'issue' => null],
            'metadata' => ['packages' => $packages],
        ];
    }

    public function test_a_refusal_detection_reports_is_not_deployable(): void
    {
        $inspection = CreateInspection::fromReport(self::report([
            'strategy' => 'python',
            'label' => 'Python',
            'deployable' => false,
            'issue' => 'No start command could be worked out.',
        ]));

        $this->assertSame(CreateInspection::NOT_DEPLOYABLE, $inspection->verdict);
        $this->assertSame('No start command could be worked out.', $inspection->toResponse()['reason'] ?? null);
        $this->assertNotEmpty($inspection->toResponse()['suggestion'] ?? null);
    }

    public function test_the_fallback_is_the_placeholder_page(): void
    {
        $inspection = CreateInspection::fromReport(self::report(['strategy' => 'fallback', 'label' => 'Unknown']));

        $this->assertSame(CreateInspection::PLACEHOLDER, $inspection->verdict);
        $this->assertStringContainsString('Project not configured', (string) $inspection->reason);
    }

    public function test_railpack_with_a_start_script_deploys(): void
    {
        $node = ['ecosystem' => 'node', 'file' => 'package.json', 'scripts' => ['start', 'test']];
        $inspection = CreateInspection::fromReport(self::report(['strategy' => 'railpack', 'label' => 'Railpack'], [$node]));

        $this->assertSame(['verdict' => 'deployable', 'strategy' => 'railpack'], $inspection->toResponse());
        $this->assertNull($inspection->warning());
    }

    public function test_railpack_without_a_start_script_has_no_start_command(): void
    {
        $node = ['ecosystem' => 'node', 'file' => 'package.json', 'scripts' => ['build', 'test']];
        $inspection = CreateInspection::fromReport(self::report(['strategy' => 'railpack', 'label' => 'Railpack'], [$node]));

        $this->assertSame(CreateInspection::NO_START_COMMAND, $inspection->verdict);
        $this->assertStringStartsWith('The repository may not deploy as it stands: ', (string) $inspection->warning());
    }

    public function test_railpack_on_another_ecosystem_is_left_to_the_build(): void
    {
        $go = ['ecosystem' => 'go', 'file' => 'go.mod', 'scripts' => []];
        $inspection = CreateInspection::fromReport(self::report(['strategy' => 'railpack', 'label' => 'Railpack'], [$go]));

        $this->assertSame(CreateInspection::DEPLOYABLE, $inspection->verdict);
    }

    public function test_it_survives_the_queue(): void
    {
        $inspection = CreateInspection::fromReport(self::report(['strategy' => 'fallback', 'label' => 'Unknown']));
        $this->assertEquals($inspection, CreateInspection::fromArray($inspection->toArray()));

        $skipped = CreateInspection::skipped('only a public github.com repository is read from its file list.');
        $this->assertEquals($skipped, CreateInspection::fromArray($skipped->toArray()));
        $this->assertNull(CreateInspection::fromArray(null));
    }

    public function test_the_deploy_log_opens_with_it(): void
    {
        $username = 'ciwarn' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $logger = DeployLogger::start($username);

        CreateInspection::fromReport(self::report(['strategy' => 'fallback', 'label' => 'Unknown']))->writeTo($logger);
        CreateInspection::skipped('it is cloned with a token.')->writeTo($logger);
        CreateInspection::fromReport(self::report(['strategy' => 'static', 'label' => 'Static']))->writeTo($logger);

        $lines = array_map(static fn (array $l): array => [$l['level'], $l['msg']], $logger->entries());
        $this->assertSame('warn', $lines[0][0]);
        $this->assertStringContainsString('it may not deploy', $lines[0][1]);
        $this->assertSame('warn', $lines[1][0]);
        $this->assertStringStartsWith('Suggestion: ', $lines[1][1]);
        $this->assertSame(['warn', 'The deploy goes ahead regardless.'], $lines[2]);
        $this->assertSame(['info', 'Repository not inspected before the clone: it is cloned with a token.'], $lines[3]);
        $this->assertSame(['info', 'Repository inspected from its file list: deploys as Static (static).'], $lines[4]);
    }

    /** The synchronous create writes it right under "Deploy started". */
    public function test_the_create_writes_it_under_the_first_line(): void
    {
        $username = 'cifirst' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $user = new User(['username' => $username, 'domain' => $username . '.test', 'details' => [
            'git_repo' => 'https://github.com/owner/repo',
            'template' => 'dind',
        ]]);
        $creator = new ProjectCreator();
        (new \ReflectionProperty(ProjectCreator::class, 'inspection'))->setValue(
            $creator,
            CreateInspection::fromReport(self::report(['strategy' => 'fallback', 'label' => 'Unknown']))
        );

        $logger = $creator->startDeployLog($user);

        $this->assertNotNull($logger);
        $messages = array_column($logger->entries(), 'msg');
        $this->assertStringStartsWith('Deploy started (source: git', $messages[0]);
        $this->assertStringContainsString('it may not deploy', $messages[1]);
    }
}

<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Maintenance;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named maintenance-mode operations: enabled body, set resource
 * body, failure as Exception, and the WP-CLI argv including resolved --path.
 * Status treats a non-zero is-active exit as enabled=false, not as a failure.
 */
class MaintenanceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_returns_enabled_true_when_is_active_exits_zero(): void
    {
        $sequence = [];
        $maintenance = $this->maintenanceWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
            ],
        );

        $body = $maintenance->get();

        $this->assertSame(
            ['maintenance-mode', 'is-active', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(['enabled' => true], $body);
    }

    public function test_get_returns_enabled_false_when_is_active_exits_nonzero(): void
    {
        $sequence = [];
        $maintenance = $this->maintenanceWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 1, 'stdout' => '', 'stderr' => ''],
            ],
        );

        $body = $maintenance->get();

        $this->assertSame(['enabled' => false], $body);
    }

    public function test_get_with_explicit_path(): void
    {
        $sequence = [];
        $maintenance = $this->maintenanceWithFakeRunner($sequence, path: '/home/alice/other.test/public_html');

        $maintenance->get();

        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[0][2]);
    }

    public function test_set_activate_passes_argv_and_returns_body(): void
    {
        $sequence = [];
        $maintenance = $this->maintenanceWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => "Success: Maintenance mode activated.\n", 'stderr' => ''],
            ],
        );

        $body = $maintenance->set(true);

        $this->assertSame(
            ['maintenance-mode', 'activate', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            ['enabled' => true, 'message' => 'Success: Maintenance mode activated.'],
            $body
        );
    }

    public function test_set_deactivate_passes_argv_and_returns_body(): void
    {
        $sequence = [];
        $maintenance = $this->maintenanceWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'Success: Maintenance mode deactivated.', 'stderr' => ''],
            ],
        );

        $body = $maintenance->set(false);

        $this->assertSame(
            ['maintenance-mode', 'deactivate', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            ['enabled' => false, 'message' => 'Success: Maintenance mode deactivated.'],
            $body
        );
    }

    public function test_set_nonzero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $maintenance = $this->maintenanceWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => 'Error: Could not activate maintenance mode.',
                    'stderr' => '',
                ],
            ],
        );

        try {
            $maintenance->set(true);
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Could not activate maintenance mode.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * @param list<list<string>> $sequence
     * @param list<array{exit_code: int, stdout: string, stderr: string}> $results
     */
    private function maintenanceWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
    ): Maintenance {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $call = 0;
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$sequence, &$call, $results): array {
                $sequence[] = $args;
                $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $call++;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new Maintenance(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}

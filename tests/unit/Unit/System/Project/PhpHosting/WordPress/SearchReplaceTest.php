<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\SearchReplace;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named search-replace: success body, failure as Exception, and
 * the WP-CLI arguments the runner received (including resolved --path).
 */
class SearchReplaceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_run_returns_body_and_passes_default_path(): void
    {
        $sequence = [];
        $op = $this->searchReplaceWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Made 3 replacements.\n", 'stderr' => '']],
        );

        $body = $op->run('http://old.test', 'https://new.test');

        $this->assertSame(
            [
                'search-replace',
                'http://old.test',
                'https://new.test',
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[0]
        );
        $this->assertSame(
            [
                'search' => 'http://old.test',
                'replacement' => 'https://new.test',
                'message' => 'Success: Made 3 replacements.',
            ],
            $body
        );
    }

    public function test_run_with_extra_args_and_explicit_path(): void
    {
        $sequence = [];
        $op = $this->searchReplaceWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Made 1 replacements.\n", 'stderr' => '']],
            path: '/home/alice/other.test/public_html',
        );

        $body = $op->run(
            'foo',
            'bar',
            ['--dry-run', '--skip-columns=guid', 'wp_posts'],
        );

        $this->assertSame(
            [
                'search-replace',
                'foo',
                'bar',
                '--dry-run',
                '--skip-columns=guid',
                'wp_posts',
                '--path=/home/alice/other.test/public_html',
            ],
            $sequence[0]
        );
        $this->assertSame('Success: Made 1 replacements.', $body['message']);
    }

    public function test_run_nonzero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $op = $this->searchReplaceWithFakeRunner(
            $sequence,
            results: [['exit_code' => 1, 'stdout' => "Error: Table 'wp_x' doesn't exist.", 'stderr' => '']],
        );

        try {
            $op->run('a', 'b');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame("Error: Table 'wp_x' doesn't exist.", $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * @param list<list<string>> $sequence
     * @param list<array{exit_code: int, stdout: string, stderr: string}> $results
     */
    private function searchReplaceWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
    ): SearchReplace {
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

        return new SearchReplace(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}

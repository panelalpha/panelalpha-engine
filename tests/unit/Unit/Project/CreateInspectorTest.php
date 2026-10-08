<?php

namespace Tests\Unit\Project;

use App\Lib\Deploy\Inspect\GitHubTree;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Project\CreateInspection;
use App\Lib\Project\CreateInspector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The inspection a create runs before cloning: the inspect endpoint's tree
 * mode, and nothing for a source that mode cannot read -- never a clone.
 */
class CreateInspectorTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/create-inspector-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        ResolvedSource::removeTree($this->tmp);
        parent::tearDown();
    }

    private function inspector(?float $budget = 20): CreateInspector
    {
        $tree = new GitHubTree(static fn (string $url, ?string $branch): array => [self::SHA, 'main'], $budget);

        return new CreateInspector(new SourceResolver($this->tmp, 20, $tree));
    }

    /**
     * owner/repo on GitHub holding exactly $files.
     *
     * @param array<string, string> $files
     */
    private static function fakeRepository(array $files, int $treeStatus = 200): void
    {
        $tree = [];
        foreach ($files as $path => $body) {
            $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'size' => strlen($body)];
        }
        Http::fake(static function (Request $request) use ($files, $tree, $treeStatus) {
            if (str_contains($request->url(), 'api.github.com/repos/owner/repo/git/trees/')) {
                return Http::response(['tree' => $tree], $treeStatus);
            }
            $prefix = 'https://raw.githubusercontent.com/owner/repo/' . self::SHA . '/';
            $path = str_starts_with($request->url(), $prefix) ? rawurldecode(substr($request->url(), strlen($prefix))) : null;

            return $path !== null && isset($files[$path]) ? Http::response($files[$path]) : Http::response('', 404);
        });
    }

    public function test_a_library_is_reported_with_its_reason_and_suggestion(): void
    {
        self::fakeRepository([
            'package.json' => '{"name":"lib","main":"lib.js","scripts":{"build":"node build.js","test":"node test"},'
                . '"devDependencies":{"chalk":"^1.1.3"}}',
            'lib.js' => 'module.exports = {};',
        ]);

        $inspection = $this->inspector()->inspect('https://github.com/owner/repo');

        $this->assertSame(CreateInspection::NO_START_COMMAND, $inspection->verdict);
        $this->assertSame('railpack', $inspection->strategy);
        $this->assertTrue($inspection->warns());
        $response = $inspection->toResponse();
        $this->assertSame(['verdict', 'strategy', 'reason', 'suggestion'], array_keys((array) $response));
        $this->assertStringContainsString('no start script', (string) $response['reason']);
        // The workspace goes as soon as the report is made.
        $this->assertSame([], glob($this->tmp . '/inspect-*') ?: []);
    }

    public function test_a_site_that_deploys_is_reported_by_its_strategy_alone(): void
    {
        self::fakeRepository([
            'index.html' => '<!doctype html><title>Spoon-Knife</title>',
            'styles.css' => 'body {}',
            'README.md' => '# Spoon-Knife',
        ]);

        $inspection = $this->inspector()->inspect('github.com/owner/repo');

        $this->assertFalse($inspection->warns());
        $this->assertSame(['verdict' => CreateInspection::DEPLOYABLE, 'strategy' => 'static'], $inspection->toResponse());
    }

    public function test_a_token_skips_it_without_asking_github(): void
    {
        Http::fake();

        $inspection = $this->inspector()->inspect('https://github.com/owner/repo', null, 'ghp_secret');

        $this->assertNull($inspection->toResponse());
        $this->assertStringContainsString('token', (string) $inspection->skipped);
        Http::assertNothingSent();
    }

    public function test_another_host_is_skipped_and_never_cloned(): void
    {
        Http::fake();

        $inspection = $this->inspector()->inspect('https://gitlab.com/owner/repo');

        $this->assertNull($inspection->toResponse());
        $this->assertStringContainsString('github.com', (string) $inspection->skipped);
        Http::assertNothingSent();
        // No clone was attempted: nothing was ever made in the workspace root.
        $this->assertDirectoryDoesNotExist($this->tmp);
    }

    public function test_a_file_list_github_refuses_is_skipped_with_its_reason(): void
    {
        self::fakeRepository(['index.html' => '<title>x</title>'], 403);

        $inspection = $this->inspector()->inspect('https://github.com/owner/repo');

        $this->assertNull($inspection->toResponse());
        $this->assertStringContainsString('GitHub answered 403', (string) $inspection->skipped);
        $this->assertSame([], glob($this->tmp . '/inspect-*') ?: []);
    }

    public function test_a_spent_budget_is_skipped_rather_than_waited_on(): void
    {
        self::fakeRepository(['index.html' => '<title>x</title>']);

        $inspection = $this->inspector(0.0)->inspect('https://github.com/owner/repo');

        $this->assertNull($inspection->toResponse());
        $this->assertStringContainsString('took longer than', (string) $inspection->skipped);
    }
}

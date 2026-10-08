<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckException;
use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use PHPUnit\Framework\TestCase;

/**
 * `expect.path` and `expect.json`: asking an API where it states its own
 * condition, and reading a document rather than a page.
 *
 * The case these exist for is market-radar: an SPA shell at `/`, its real
 * condition at `/api/health`, and every baseline check passing on a deploy
 * whose database was unreachable. So the load-bearing tests here are that a
 * check can be asked about another path at all, and that a body which is not
 * JSON fails a check that demanded JSON.
 */
class PathAndJsonChecksTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-path-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->dir);
        parent::tearDown();
    }

    /** A check built in memory, so the shipped tree is not involved. */
    private function check(array $raw): HealthCheck
    {
        return HealthCheck::fromArray($raw, 'test', 'test/one.yaml');
    }

    private function runner(HealthCheck ...$checks): CheckRunner
    {
        // Through the registry's own selection so the group and the shipped
        // baseline are real; the extra check arrives by an id no group has, so
        // it is injected directly instead.
        return new CheckRunner([...CheckRegistry::for(null), ...$checks]);
    }

    private function find(array $report, string $id): ?array
    {
        foreach ($report['checks'] as $check) {
            if (($check['id'] ?? null) === $id) {
                return $check;
            }
        }

        return null;
    }

    public function test_a_check_may_name_the_path_it_is_asked_about(): void
    {
        $check = $this->check([
            'id' => 'health-ok',
            'message' => 'The API is not reporting itself healthy.',
            'expect' => ['path' => '/api/health', 'status' => [200]],
        ]);

        $this->assertSame('/api/health', $check->path());
    }

    public function test_the_default_path_is_the_root(): void
    {
        $check = $this->check([
            'id' => 'root-ok',
            'message' => 'The front page is missing.',
            'expect' => ['status' => [200]],
        ]);

        $this->assertSame('/', $check->path());
        $this->assertSame('/', HealthCheck::DEFAULT_PATH);
    }

    /**
     * The whole point: asked about `/api/health`, answered by `/api/health`.
     * Asked about the wrong path it would see the SPA shell and pass.
     */
    public function test_a_check_is_asked_about_the_path_it_named(): void
    {
        $check = $this->check([
            'id' => 'database-connected',
            'message' => 'The API cannot reach its database.',
            'serving' => 'database_error',
            'expect' => ['path' => '/api/health', 'json' => ['database' => 'connected']],
        ]);

        $healthy = $this->runner($check)->runByPath([
            '/' => new ProbedResponse(200, '<div id="root"></div>', 'http://127.0.0.1:4000/'),
            '/api/health' => new ProbedResponse(200, '{"status":"ok","database":"connected"}', 'http://127.0.0.1:4000/api/health'),
        ], $this->dir);

        $this->assertSame(CheckResult::STATUS_PASS, $this->find($healthy, 'database-connected')['status']);

        // The same check against a database that is down: the page still 200s.
        $broken = $this->runner($check)->runByPath([
            '/' => new ProbedResponse(200, '<div id="root"></div>', 'http://127.0.0.1:4000/'),
            '/api/health' => new ProbedResponse(200, '{"status":"error","database":"unreachable"}', 'http://127.0.0.1:4000/api/health'),
        ], $this->dir);

        $failed = $this->find($broken, 'database-connected');
        $this->assertSame(CheckResult::STATUS_FAIL, $failed['status']);
        // The application itself is fine; what it says about its database is
        // not. That distinction is the one the report has to carry.
        $this->assertSame('database_error', $broken['serving']);
    }

    /**
     * A path the caller never fetched is a failure, not a skip.
     *
     * This is the regression guard for the original bug in a different
     * costume: a check that silently stops checking is worse than one that
     * reports it could not.
     */
    public function test_a_check_whose_path_was_not_fetched_fails_with_the_reason(): void
    {
        $check = $this->check([
            'id' => 'unfetched',
            'message' => 'The API is not healthy.',
            'expect' => ['path' => '/api/health', 'json' => ['status' => 'ok']],
        ]);

        $report = $this->runner($check)->runByPath([
            '/' => new ProbedResponse(200, 'hello', 'http://127.0.0.1:4000/'),
        ], $this->dir);

        $failed = $this->find($report, 'unfetched');
        $this->assertSame(CheckResult::STATUS_FAIL, $failed['status']);
        $this->assertStringContainsString('/api/health', $failed['title']);
        $this->assertSame('/api/health', $failed['evidence']['url']);
    }

    public function test_json_members_are_compared_by_value(): void
    {
        $cases = [
            ['{"status":"ok"}', ['status' => 'ok'], true],
            ['{"status":"ok"}', ['status' => 'down'], false],
            ['{"healthy":true}', ['healthy' => true], true],
            ['{"healthy":true}', ['healthy' => false], false],
            ['{"accounts":3}', ['accounts' => 3], true],
            ['{"version":1.0}', ['version' => '1.0'], true, 'a number and its string spelling'],
            ['{"status":"true"}', ['status' => true], false, 'a string is not a boolean'],
            ['{"status":"ok"}', ['database' => 'connected'], false, 'a member that is absent'],
            ['{"status":"ok","database":"connected"}', ['status' => 'ok', 'database' => 'connected'], true],
            ['{"status":"ok","database":"down"}', ['status' => 'ok', 'database' => 'connected'], false],
        ];

        foreach ($cases as $case) {
            [$body, $json, $expected] = $case;
            $why = $case[3] ?? $body;

            $check = $this->check([
                'id' => 'json-check',
                'message' => 'The API contract is broken.',
                'expect' => ['json' => $json],
            ]);

            $report = $this->runner($check)->run(new ProbedResponse(200, $body, 'http://127.0.0.1:4000/'), $this->dir);
            $status = $this->find($report, 'json-check')['status'];

            $this->assertSame(
                $expected ? CheckResult::STATUS_PASS : CheckResult::STATUS_FAIL,
                $status,
                "{$why}: " . var_export($json, true)
            );
        }
    }

    /** A list is "any of these", which is how two spellings of one state pass. */
    public function test_a_json_member_may_name_several_accepted_values(): void
    {
        $check = $this->check([
            'id' => 'either-spelling',
            'message' => 'The API is not healthy.',
            'expect' => ['json' => ['status' => ['ok', 'healthy']]],
        ]);

        foreach (['ok', 'healthy'] as $value) {
            $report = $this->runner($check)->run(
                new ProbedResponse(200, json_encode(['status' => $value]), 'http://127.0.0.1:4000/'),
                $this->dir
            );
            $this->assertSame(CheckResult::STATUS_PASS, $this->find($report, 'either-spelling')['status'], $value);
        }
    }

    /** A page of HTML where JSON was demanded has found a broken contract. */
    public function test_a_body_that_is_not_json_fails_a_json_expectation(): void
    {
        $check = $this->check([
            'id' => 'must-be-json',
            'message' => 'The API did not answer with JSON.',
            'expect' => ['json' => ['status' => 'ok']],
        ]);

        $report = $this->runner($check)->run(
            new ProbedResponse(200, '<!doctype html><title>App</title>', 'http://127.0.0.1:4000/'),
            $this->dir
        );

        $this->assertSame(CheckResult::STATUS_FAIL, $this->find($report, 'must-be-json')['status']);
    }

    /**
     * `//host/x` is a protocol-relative URL, and would send the engine's probe
     * to somebody else's server. Refused, as is anything not a local path.
     */
    public function test_a_path_that_is_not_local_is_refused(): void
    {
        foreach (['//evil.test/x', 'https://evil.test/', 'api/health', '/a b', "/a\nb", ''] as $path) {
            try {
                $this->check([
                    'id' => 'bad-path',
                    'message' => 'Nope.',
                    'expect' => ['path' => $path, 'status' => [200]],
                ]);
            } catch (CheckException $e) {
                $this->assertStringContainsString('path', $e->getMessage(), $path);

                continue;
            }

            $this->fail("Accepted a non-local path: " . var_export($path, true));
        }
    }

    /** A query string is a real health endpoint's shape, so it stays allowed. */
    public function test_a_query_string_is_allowed(): void
    {
        $check = $this->check([
            'id' => 'deep-health',
            'message' => 'The API is not healthy.',
            'expect' => ['path' => '/health?deep=1', 'status' => [200]],
        ]);

        $this->assertSame('/health?deep=1', $check->path());
    }

    /**
     * `path` says where to look, not what must be there. A check carrying only
     * that would pass whatever it found.
     */
    public function test_a_check_asserting_only_a_path_is_refused(): void
    {
        $this->expectException(CheckException::class);
        $this->expectExceptionMessage('can never fail');

        $this->check([
            'id' => 'path-only',
            'message' => 'Nothing is asserted.',
            'expect' => ['path' => '/api/health'],
        ]);
    }

    /** A nested shape is a claim about a document, which this vocabulary refuses. */
    public function test_a_nested_json_expectation_is_refused(): void
    {
        $this->expectException(CheckException::class);
        $this->expectExceptionMessage('belongs in an explain class');

        $this->check([
            'id' => 'nested',
            'message' => 'Too deep.',
            'expect' => ['json' => ['database' => ['host' => 'localhost']]],
        ]);
    }

    public function test_an_empty_json_expectation_is_refused(): void
    {
        $this->expectException(CheckException::class);
        $this->expectExceptionMessage("'json' must be a non-empty object");

        $this->check([
            'id' => 'empty-json',
            'message' => 'Nothing is asserted.',
            'expect' => ['json' => []],
        ]);
    }
}

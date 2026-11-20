<?php

namespace Tests\Integration\Deploy\Health;

use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ExplainerRegistry;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\TestNetwork;

/**
 * A failing check keeps its own finding when its explainer is unknown or throws, and a JSON member asked
 * to be a boolean is not satisfied by a string.
 */
class CheckRunnerExplainTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        ExplainerRegistry::flush();
        $this->dir = sys_get_temp_dir().'/pa-runner-explain-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        ExplainerRegistry::flush();
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function check(array $raw): HealthCheck
    {
        return HealthCheck::fromArray($raw + ['id' => 'answers', 'message' => 'The site did not answer 200.', 'fix' => 'Own fix.'], 'test', 'test/answers.yaml');
    }

    private function outcome(HealthCheck $check, ProbedResponse $response): CheckResult
    {
        return (new CheckRunner([$check]))->results($response, $this->dir)[0];
    }

    private function response(int $status, string $body = ''): ProbedResponse
    {
        return new ProbedResponse($status, $body, 'http://'.TestNetwork::LOOPBACK.':8000/');
    }

    public function test_an_explainer_nobody_ships_leaves_the_check_its_own_fix(): void
    {
        $result = $this->outcome($this->check(['expect' => ['status' => [200]], 'explain' => 'no-such-explainer']), $this->response(500));

        $this->assertSame(CheckResult::STATUS_FAIL, $result->status);
        $this->assertNull($result->detail);
        $this->assertSame('Own fix.', $result->fix);
    }

    public function test_an_explainer_that_throws_costs_the_finding_nothing(): void
    {
        $explainers = ExplainerRegistry::all();
        $explainers['throws'] = new class implements Explainer
        {
            public function id(): string
            {
                return 'throws';
            }

            public function explain(ProjectContext $context, ProbedResponse $response): array
            {
                throw new \RuntimeException('cannot read the project');
            }
        };
        (new ReflectionProperty(ExplainerRegistry::class, 'explainers'))->setValue(null, $explainers);

        $result = $this->outcome($this->check(['expect' => ['status' => [200]], 'explain' => 'throws']), $this->response(500));

        $this->assertSame(CheckResult::STATUS_FAIL, $result->status);
        $this->assertSame('The site did not answer 200.', $result->title);
        $this->assertNull($result->detail);
        $this->assertSame('Own fix.', $result->fix);
        $this->assertSame('Check test/answers: The site did not answer 200.', $result->describe());
    }

    public function test_a_boolean_member_is_not_matched_by_its_spelling(): void
    {
        $check = $this->check(['expect' => ['json' => ['ok' => true]]]);

        $this->assertSame(CheckResult::STATUS_FAIL, $this->outcome($check, $this->response(200, '{"ok":"true"}'))->status);
        $this->assertSame(CheckResult::STATUS_PASS, $this->outcome($check, $this->response(200, '{"ok":true}'))->status);
    }

    public function test_an_explained_failure_describes_itself_with_the_detail(): void
    {
        file_put_contents($this->dir.'/about.html', '<h1>About</h1>');
        file_put_contents($this->dir.'/contact.html', '<h1>Contact</h1>');
        file_put_contents($this->dir.'/server.py', 'print(1)');

        $result = $this->outcome($this->check(['expect' => ['status' => [200]], 'explain' => 'static-entry']), $this->response(404));

        $this->assertSame(
            'Check test/answers: The site did not answer 200. about.html, contact.html are still in ~/project, '
            .'but the document the deployed site is served from is not.',
            $result->describe()
        );
    }

    public function test_a_passing_check_describes_itself_as_passed(): void
    {
        $result = $this->outcome($this->check(['expect' => ['status' => [200]]]), $this->response(200));

        $this->assertSame('Check test/answers: passed', $result->describe());
    }
}

<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\PlatformManifest;
use PHPUnit\Framework\TestCase;

/**
 * The third question a health report asks: is what answered the application,
 * or is it us?
 *
 * Every case below is one the port probe passes. That is the point — a 200
 * carrying the engine's own placeholder and a 200 carrying a homepage are
 * identical at the socket, and a 404 on `/` is under the server-error
 * threshold, so `healthy` says yes to all of them.
 */
class CheckRunnerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-checks-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $contents = '<h1>hi</h1>'): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    /**
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    private function probe(int $status, string $body): array
    {
        return CheckRunner::for(PlatformManifest::RUNTIME_NGINX)
            ->run(new ProbedResponse($status, $body, 'http://127.0.0.1:8080/'), $this->dir);
    }

    /**
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    private function probeTimed(int $status, string $body, float $time): array
    {
        return CheckRunner::for(PlatformManifest::RUNTIME_NGINX)
            ->run(new ProbedResponse($status, $body, 'http://127.0.0.1:8080/', $time), $this->dir);
    }

    /**
     * @param array{checks: list<array<string, mixed>>} $report
     * @return array<string, mixed>|null
     */
    private function check(array $report, string $id): ?array
    {
        foreach ($report['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        return null;
    }

    public function test_a_site_serving_itself_passes_everything(): void
    {
        $this->write('index.html');

        $report = $this->probe(200, '<!doctype html><title>A real site</title>');

        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
        foreach ($report['checks'] as $check) {
            $this->assertSame(CheckResult::STATUS_PASS, $check['status'], $check['id'] . ' should pass');
        }
    }

    /**
     * The failure the mechanism exists for: green deploy, answering port,
     * resolving domain, and PanelAlpha's page instead of the customer's.
     */
    public function test_the_not_configured_placeholder_is_an_error(): void
    {
        $this->write('home.html');

        $report = $this->probe(200, '<html><title>PanelAlpha — Project not configured</title></html>');
        $check = $this->check($report, 'not-placeholder');

        $this->assertSame('placeholder', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity']);
        // Read from the project, not from the response.
        $this->assertStringContainsString('1 file', (string) $check['detail']);
    }

    /**
     * The other placeholder, and the reason severity is not decoration: an
     * account nobody has deployed into yet is supposed to show this, and
     * calling it an error would put a red mark against every new account.
     */
    public function test_the_welcome_page_on_an_empty_project_is_only_a_warning(): void
    {
        $report = $this->probe(200, '<html><title>PanelAlpha — Ready</title></html>');
        $check = $this->check($report, 'no-welcome-page');

        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_WARNING, $check['severity']);
        $this->assertSame('no_application', $report['serving']);
        $this->assertStringContainsString('empty', (string) $check['detail']);
    }

    /**
     * Both placeholders at once cannot happen, but an error and a warning
     * together can — and the one somebody has to act on is the word the
     * report leads with.
     */
    public function test_an_error_names_the_verdict_ahead_of_a_warning(): void
    {
        $this->write('home.html');

        $report = $this->probe(200, '<title>PanelAlpha — Ready</title><p>PanelAlpha — Project not configured</p>');

        $this->assertSame('placeholder', $report['serving']);
    }

    /**
     * Measured on a live deploy: delete a site's entry document and `/`
     * answers 404 while every other page answers 200.
     */
    public function test_a_static_site_that_lost_its_front_page_is_reported(): void
    {
        $this->write('about.html');
        $this->write('contact.html');

        $report = $this->probe(404, '<html><head><title>404 Not Found</title></head></html>');
        $check = $this->check($report, 'entry-served');

        $this->assertSame('missing_entry', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertStringContainsString('404', (string) $check['title']);
        // The explainer names the pages that are still there, which is what
        // tells the owner what happened rather than that something did.
        $this->assertStringContainsString('about.html', (string) $check['detail']);
    }

    public function test_a_project_with_no_documents_at_all_says_so(): void
    {
        $report = $this->probe(404, 'not found');

        $this->assertStringContainsString('no HTML document', (string) $this->check($report, 'entry-served')['detail']);
    }

    public function test_a_directory_listing_is_an_error(): void
    {
        $this->write('about.html');

        $report = $this->probe(200, "<html><head><title>Index of /</title></head><body><a href=\"about.html\">");

        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'no-directory-listing')['status']);
        $this->assertSame('directory_listing', $report['serving']);
    }

    /** A 4xx stays healthy; a 5xx is the application failing behind an open port. */
    public function test_a_server_error_is_reported_and_a_client_error_is_not(): void
    {
        $this->write('index.html');

        $this->assertSame(
            CheckResult::STATUS_FAIL,
            $this->check($this->probe(502, 'Bad Gateway'), 'no-server-error')['status']
        );
        $this->assertSame(
            CheckResult::STATUS_PASS,
            $this->check($this->probe(401, 'Unauthorized'), 'no-server-error')['status']
        );
    }

    /**
     * A fast 502 is the engine's proxy with no upstream, not the app failing:
     * it must point at `docker logs`, and a slow 5xx must still point at the
     * application's own log. The tell is response time.
     */
    public function test_a_fast_502_blames_the_proxy_and_a_slow_5xx_blames_the_app(): void
    {
        $this->write('index.html');

        $proxy = $this->check($this->probeTimed(502, 'Bad Gateway', 0.002), 'no-server-error');
        $this->assertSame(CheckResult::STATUS_FAIL, $proxy['status']);
        $this->assertStringContainsString('docker logs', $proxy['fix']);
        $this->assertStringContainsString('crash-looping', $proxy['detail']);
        $this->assertStringNotContainsString('Read the application log', $proxy['fix']);

        $app = $this->check($this->probeTimed(500, 'Internal Server Error', 0.4), 'no-server-error');
        $this->assertSame(CheckResult::STATUS_FAIL, $app['status']);
        $this->assertSame('Read the application log.', $app['fix']);
        $this->assertStringContainsString('running container', $app['detail']);
    }

    /**
     * A silent application is the port probe's finding and it has already
     * been reported. Asking content questions of a response nobody received
     * would report every check as failed and bury the one fact that matters.
     */
    public function test_nothing_is_asked_of_an_application_that_never_answered(): void
    {
        $report = CheckRunner::for(PlatformManifest::RUNTIME_NGINX)
            ->run(ProbedResponse::none(), $this->dir);

        $this->assertSame([], $report['checks']);
        $this->assertSame(CheckRunner::SERVING_UNKNOWN, $report['serving']);
    }

    /**
     * The baseline runs for a runtime that has no group of its own yet.
     * `compose` and `dockerfile` are the two: what a compose file serves is
     * whatever image somebody chose, so there is nothing runtime-specific to
     * ask it beyond what every application is asked.
     */
    public function test_a_runtime_without_a_group_still_gets_the_baseline(): void
    {
        $report = CheckRunner::for(PlatformManifest::RUNTIME_COMPOSE)
            ->run(new ProbedResponse(200, 'hello', 'http://127.0.0.1:8000/'), $this->dir);

        $ids = array_column($report['checks'], 'id');
        $this->assertContains('not-placeholder', $ids);
        $this->assertNotContains('php-executes', $ids);
    }

    /** An unknown runtime is not an error: it is a stack with nothing extra to ask. */
    public function test_an_unknown_runtime_falls_back_to_the_baseline(): void
    {
        $report = CheckRunner::for('nothing-like-this')
            ->run(new ProbedResponse(200, 'hello', 'http://127.0.0.1:1/'), $this->dir);

        $this->assertNotSame([], $report['checks']);
        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
    }
}

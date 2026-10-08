<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\PlatformManifest;
use PHPUnit\Framework\TestCase;

/**
 * The `command` group, and the checks that belong to no runtime at all.
 *
 * The division is the point. A runtime group exists when that runtime
 * produces evidence nothing else can — PHP's error markup, Express's
 * `Cannot GET /`, the entry document nginx was pointed at. `compose` and
 * `dockerfile` produce none, because the engine did not write their recipe
 * and so has nothing specific to assert about the result; what breaks them is
 * a stock server page, a directory listing, a dev server or an unreachable
 * sidecar, and every one of those reads identically whoever built the image.
 * Those live in the baseline, where every runtime is asked them.
 *
 * The framework checks in `command` are the exception: Django's DisallowedHost
 * page reads the same whoever built the image, so compose and dockerfile
 * borrow that group. Each is guarded by `when: file:`.
 */
class CommandAndSharedChecksTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-cmd-' . bin2hex(random_bytes(8));
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

    /**
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    private function probe(string $runtime, int $status, string $body): array
    {
        return CheckRunner::for($runtime)
            ->run(new ProbedResponse($status, $body, 'http://127.0.0.1:8000/'), $this->dir);
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

    // ---- command -----------------------------------------------------

    /**
     * The first thing a Django project does after a successful deploy, at a
     * 400 that every other signal reads as healthy.
     */
    public function test_django_rejecting_its_own_domain_is_reported(): void
    {
        file_put_contents($this->dir . '/manage.py', '#!/usr/bin/env python');

        $report = $this->probe(
            PlatformManifest::RUNTIME_COMMAND,
            400,
            'Invalid HTTP_HOST header: &#x27;app.example.com&#x27;. You may need to add to ALLOWED_HOSTS.'
        );
        $check = $this->check($report, 'django-allowed-hosts');

        $this->assertSame('misconfigured_host', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity']);
    }

    /** A Go binary cannot render a Django page, so it is never asked. */
    public function test_a_project_without_django_is_not_asked_about_allowed_hosts(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_COMMAND, 200, 'hello from go');

        $this->assertSame(CheckResult::STATUS_SKIPPED, $this->check($report, 'django-allowed-hosts')['status']);
        $this->assertSame(CheckResult::STATUS_SKIPPED, $this->check($report, 'rails-blocked-host')['status']);
        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
    }

    /**
     * The engine installs an initializer that should stop this happening. A
     * guard that silently stops working is worse than one that was never
     * there, and this is the response that says it did.
     */
    public function test_rails_host_authorization_blocking_the_domain_is_reported(): void
    {
        file_put_contents($this->dir . '/Gemfile', 'source "https://rubygems.org"');

        $report = $this->probe(
            PlatformManifest::RUNTIME_COMMAND,
            403,
            '<title>Blocked hosts</title><p>To allow requests to app.example.com, add the following</p>'
        );

        $this->assertSame('misconfigured_host', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'rails-blocked-host')['status']);
    }

    /**
     * A freshly generated project that nobody has written yet is not a broken
     * deploy — it is one with no application in it.
     */
    public function test_a_framework_welcome_page_is_only_a_warning(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_COMMAND,
            200,
            '<h1>The install worked successfully! Congratulations!</h1>'
        );
        $check = $this->check($report, 'not-a-framework-default-page');

        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_WARNING, $check['severity']);
        $this->assertSame('framework_default', $report['serving']);
    }

    // ---- the checks that belong to no runtime -------------------------

    /**
     * The gap that moved this out of the node group: a Next.js project in a
     * hand-written Dockerfile is `runtime: dockerfile`, and was the one shape
     * of this failure node could never see.
     */
    public function test_a_dockerfile_project_running_a_dev_server_is_now_caught(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_DOCKERFILE,
            200,
            '<script src="/_next/webpack-hmr"></script><h1>App</h1>'
        );

        $this->assertSame('dev_server', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'not-a-dev-server')['status']);
    }

    /** A COPY that landed one directory off, in a Dockerfile the engine did not write. */
    public function test_a_stock_server_page_is_reported_for_a_dockerfile_project(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_DOCKERFILE,
            200,
            '<html><head><title>Welcome to nginx!</title></head></html>'
        );

        $this->assertSame('default_page', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'not-a-stock-default-page')['status']);
    }

    /**
     * A stack is more than the one container that gets probed. The web server
     * answering says nothing about the database behind it.
     */
    public function test_a_compose_project_that_cannot_reach_a_service_is_reported(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_COMPOSE,
            200,
            'Error: connect ECONNREFUSED 172.18.0.3:5432'
        );

        $this->assertSame('dependency_unreachable', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'no-service-unreachable')['status']);
    }

    public function test_a_hostname_that_resolves_nowhere_is_the_same_finding(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_COMPOSE,
            200,
            'could not translate host name "postgress" to address'
        );

        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'no-service-unreachable')['status']);
    }

    /** Every runtime is asked the shared set, including the two with no group. */
    public function test_compose_and_dockerfile_are_asked_the_baseline(): void
    {
        foreach ([PlatformManifest::RUNTIME_COMPOSE, PlatformManifest::RUNTIME_DOCKERFILE] as $runtime) {
            $ids = array_column($this->probe($runtime, 200, 'a working app')['checks'], 'id');

            $this->assertContains('not-a-stock-default-page', $ids, $runtime);
            $this->assertContains('no-service-unreachable', $ids, $runtime);
            $this->assertContains('not-a-dev-server', $ids, $runtime);
            $this->assertContains('no-directory-listing', $ids, $runtime);
            // And nothing another runtime owns.
            $this->assertNotContains('php-executes', $ids, $runtime);
            $this->assertNotContains('entry-served', $ids, $runtime);
        }
    }

    public function test_an_ordinary_page_trips_none_of_the_shared_checks(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_COMPOSE, 200, '<h1>My application</h1>');

        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
        foreach ($report['checks'] as $check) {
            // Guarded framework checks skip a project they do not apply to.
            $this->assertNotSame(CheckResult::STATUS_FAIL, $check['status'], $check['id']);
        }
    }
}

<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckException;
use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\PlatformManifest;
use PHPUnit\Framework\TestCase;

/**
 * Which checks an application is asked, and what a malformed check file does.
 *
 * The selection rule is the whole design: a group is picked up from the
 * manifest's `runtime`, so fifteen PHP manifests share one set of checks
 * without any of them saying so. A manifest's own `check:` list is additions
 * on top of that, never the group it already has.
 */
class CheckRegistryTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-reg-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_runtime_brings_its_group_without_the_manifest_asking(): void
    {
        $ids = array_map(
            static fn (HealthCheck $c): string => $c->reference(),
            CheckRegistry::for(PlatformManifest::RUNTIME_NGINX)
        );

        $this->assertContains('_baseline/not-placeholder', $ids);
        $this->assertContains('nginx/entry-served', $ids);
    }

    public function test_a_manifest_can_add_one_check_by_reference(): void
    {
        $ids = array_map(
            static fn (HealthCheck $c): string => $c->reference(),
            CheckRegistry::for(PlatformManifest::RUNTIME_PHP, ['node/no-missing-root-route'])
        );

        $this->assertContains('node/no-missing-root-route', $ids);
        $this->assertNotContains('node/no-stack-trace', $ids);
    }

    public function test_a_manifest_can_add_a_whole_group(): void
    {
        $ids = array_map(
            static fn (HealthCheck $c): string => $c->reference(),
            CheckRegistry::for(PlatformManifest::RUNTIME_PHP, ['node'])
        );

        $this->assertContains('node/no-missing-root-route', $ids);
        $this->assertContains('node/no-stack-trace', $ids);
    }

    /**
     * Naming a check the runtime already brought is redundant rather than
     * wrong; running it twice would report it twice.
     */
    public function test_a_check_named_twice_is_asked_once(): void
    {
        $ids = array_map(
            static fn (HealthCheck $c): string => $c->reference(),
            CheckRegistry::for(PlatformManifest::RUNTIME_NGINX, ['nginx', 'nginx/entry-served'])
        );

        $this->assertSame(array_unique($ids), $ids);
    }

    /**
     * A Django app shipping its own compose file or Dockerfile is deployed
     * under the compose/dockerfile runtime, which has no group of its own, so
     * `django-allowed-hosts` never ran for it (#209: Tandoor, Shynet).
     */
    public function test_compose_and_dockerfile_are_asked_the_framework_checks(): void
    {
        foreach ([PlatformManifest::RUNTIME_COMPOSE, PlatformManifest::RUNTIME_DOCKERFILE] as $runtime) {
            $ids = array_map(
                static fn (HealthCheck $c): string => $c->reference(),
                CheckRegistry::for($runtime)
            );

            $this->assertContains('command/django-allowed-hosts', $ids, $runtime);
            $this->assertContains('command/rails-blocked-host', $ids, $runtime);
            $this->assertContains('_baseline/no-server-error', $ids, $runtime);
        }
    }

    /** Tandoor's steady state: 400 DisallowedHost on every path, scored `ok`. */
    public function test_a_compose_django_app_refusing_its_domain_is_not_ok(): void
    {
        file_put_contents($this->dir . '/manage.py', '#!/usr/bin/env python');

        $report = CheckRunner::for(PlatformManifest::RUNTIME_COMPOSE)->run(
            new ProbedResponse(400, '<h1>Bad Request (400)</h1> DisallowedHost at /', 'http://127.0.0.1:8080/'),
            $this->dir
        );

        unlink($this->dir . '/manage.py');

        $this->assertSame('misconfigured_host', $report['serving']);
    }

    /** With DEBUG off (Tandoor's DEBUG=0) the page names nothing but the 400. */
    public function test_django_production_400_page_is_recognised(): void
    {
        file_put_contents($this->dir . '/manage.py', '#!/usr/bin/env python');
        // Django's stock templates/400 page, as runserver sends it with DEBUG = False.
        $body = "<!doctype html>\n<html lang=\"en\">\n<head>\n  <title>Bad Request (400)</title>\n</head>\n"
            . "<body>\n  <h1>Bad Request (400)</h1><p></p>\n</body>\n</html>\n";

        $report = CheckRunner::for(PlatformManifest::RUNTIME_COMPOSE)->run(
            new ProbedResponse(400, $body, 'http://127.0.0.1:8000/'),
            $this->dir
        );

        unlink($this->dir . '/manage.py');

        $this->assertSame('misconfigured_host', $report['serving']);
    }

    /** The guard keeps it silent for everything that is not Django. */
    public function test_the_borrowed_django_check_skips_a_project_without_manage_py(): void
    {
        $report = CheckRunner::for(PlatformManifest::RUNTIME_COMPOSE)->run(
            new ProbedResponse(400, 'DisallowedHost', 'http://127.0.0.1:8080/'),
            $this->dir
        );

        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
    }

    /** A typo must be a failed contract test, never a check that never runs. */
    public function test_an_unknown_reference_throws(): void
    {
        $this->expectException(CheckException::class);
        CheckRegistry::for(null, ['nginx/does-not-exist']);
    }

    public function test_an_unknown_group_throws(): void
    {
        $this->expectException(CheckException::class);
        CheckRegistry::for(null, ['perl']);
    }

    /**
     * How one member of a group opts out of a check the group brings: a guard
     * on the check, so the reason lives next to the check rather than
     * scattered across the manifests that skip it.
     */
    public function test_a_when_guard_skips_a_check_the_project_does_not_answer_for(): void
    {
        $guarded = HealthCheck::fromArray([
            'id' => 'needs-wordpress',
            'when' => ['file' => 'wp-config.php'],
            'expect' => ['body_not' => ['anything at all']],
            'message' => 'never seen',
            'fix' => 'never needed',
        ], 'test', 'test/needs-wordpress.yaml');

        $report = (new CheckRunner([$guarded]))
            ->run(new ProbedResponse(200, 'anything at all', 'http://127.0.0.1:80/'), $this->dir);

        $this->assertSame(CheckResult::STATUS_SKIPPED, $report['checks'][0]['status']);
        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
    }

    public function test_a_guarded_check_still_runs_where_it_applies(): void
    {
        file_put_contents($this->dir . '/wp-config.php', '<?php');

        $guarded = HealthCheck::fromArray([
            'id' => 'needs-wordpress',
            'when' => ['file' => 'wp-config.php'],
            'expect' => ['body_not' => ['database error']],
            'message' => 'The application cannot reach its database.',
            'fix' => 'Check the credentials.',
        ], 'test', 'test/needs-wordpress.yaml');

        $report = (new CheckRunner([$guarded]))
            ->run(new ProbedResponse(200, 'database error', 'http://127.0.0.1:80/'), $this->dir);

        unlink($this->dir . '/wp-config.php');

        $this->assertSame(CheckResult::STATUS_FAIL, $report['checks'][0]['status']);
    }

    /**
     * The `expect` vocabulary is four keys and is meant to stay four: a
     * question needing more belongs in an explain class, the way `detect`
     * sends anything needing judgement to a probe.
     */
    public function test_an_unknown_expect_key_is_refused_with_the_reason(): void
    {
        $this->expectException(CheckException::class);
        $this->expectExceptionMessageMatches('/explain class/');

        HealthCheck::fromArray([
            'id' => 'too-clever',
            'expect' => ['body_matches' => '/^regex$/'],
            'message' => 'x',
        ], 'test', 'test/too-clever.yaml');
    }

    /** A check that asserts nothing passes for everything ever deployed. */
    public function test_a_check_that_can_never_fail_is_refused(): void
    {
        $this->expectException(CheckException::class);
        $this->expectExceptionMessageMatches('/never fail/');

        HealthCheck::fromArray(['id' => 'empty', 'message' => 'x'], 'test', 'test/empty.yaml');
    }

    public function test_severity_must_be_one_of_the_three(): void
    {
        $this->expectException(CheckException::class);

        HealthCheck::fromArray([
            'id' => 'loud',
            'severity' => 'critical',
            'expect' => ['status' => [200]],
            'message' => 'x',
        ], 'test', 'test/loud.yaml');
    }

    public function test_severity_defaults_to_error(): void
    {
        $check = HealthCheck::fromArray([
            'id' => 'quiet',
            'expect' => ['status' => [200]],
            'message' => 'x',
        ], 'test', 'test/quiet.yaml');

        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check->severity);
    }
}

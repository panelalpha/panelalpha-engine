<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\PlatformManifest;
use PHPUnit\Framework\TestCase;

/**
 * The two groups that cover most of the shipped manifests: fifteen run PHP
 * and nine run Node, and neither writes a line of check configuration.
 *
 * Every case here answers 200. That is the whole reason the group exists —
 * an application that renders its own database error, prints a fatal into
 * the page, or hands the browser its own source is up as far as any status
 * code is concerned.
 */
class PhpAndNodeChecksTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-groups-' . bin2hex(random_bytes(8));
        mkdir($this->dir . '/public', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/public', ''] as $sub) {
            $path = $this->dir . $sub;
            if (!is_dir($path)) {
                continue;
            }
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file($path . '/' . $entry)) {
                    unlink($path . '/' . $entry);
                }
            }
        }
        if (is_dir($this->dir . '/public')) {
            rmdir($this->dir . '/public');
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

    // ---- php ---------------------------------------------------------

    public function test_a_php_app_that_works_passes_its_group(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_PHP, 200, '<h1>A working site</h1>');

        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
        foreach ($report['checks'] as $check) {
            $this->assertSame(CheckResult::STATUS_PASS, $check['status'], $check['id']);
        }
    }

    /**
     * The document root is above the application, so the interpreter never
     * sees the file and the browser gets the program — and its credentials.
     */
    public function test_php_served_as_text_is_reported_with_the_docroot_to_set(): void
    {
        file_put_contents($this->dir . '/public/index.php', '<?php $db = "secret";');

        $report = $this->probe(PlatformManifest::RUNTIME_PHP, 200, '<?php $db = "secret";');
        $check = $this->check($report, 'php-executes');

        $this->assertSame('php_source', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertStringContainsString('public/index.php', (string) $check['detail']);
        $this->assertStringContainsString('docroot: public', (string) $check['fix']);
    }

    public function test_an_index_php_at_the_root_gets_the_other_diagnosis(): void
    {
        file_put_contents($this->dir . '/index.php', '<?php echo 1;');

        $check = $this->check($this->probe(PlatformManifest::RUNTIME_PHP, 200, '<?php echo 1;'), 'php-executes');

        $this->assertStringContainsString('at the project root', (string) $check['detail']);
    }

    public function test_an_application_that_cannot_reach_its_database_is_an_error(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_PHP,
            200,
            '<html><body>Error establishing a database connection</body></html>'
        );

        $this->assertSame('database_error', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'no-database-error')['status']);
    }

    public function test_a_pdo_failure_is_caught_too(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_PHP, 200, 'SQLSTATE[HY000] [2002] Connection refused');

        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'no-database-error')['status']);
    }

    /** display_errors on: PHP prints the fatal and the status stays 200. */
    public function test_a_fatal_error_rendered_into_the_page_is_reported(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_PHP,
            200,
            "<br />\n<b>Fatal error</b>:  Uncaught Error: Class not found in /app/index.php:4"
        );

        $this->assertSame('php_error', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'no-fatal-error')['status']);
    }

    /**
     * Leaked diagnostics are the group's warning: the site works, it is just
     * telling visitors things they were never meant to see.
     */
    public function test_leaked_warnings_are_a_warning_and_not_an_error(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_PHP,
            200,
            "<br />\n<b>Deprecated</b>:  strlen(): Passing null is deprecated<h1>The site</h1>"
        );
        $check = $this->check($report, 'no-diagnostics-in-output');

        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_WARNING, $check['severity']);
        // Nothing is being served *instead* of the application, so the
        // one-word verdict stays ok.
        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
    }

    /** PHP's own markup, so ordinary prose is not a finding. */
    public function test_a_page_that_merely_talks_about_warnings_is_not_reported(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_PHP, 200, '<h1>Warning: wet floor</h1>');

        $this->assertSame(CheckResult::STATUS_PASS, $this->check($report, 'no-diagnostics-in-output')['status']);
        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
    }

    /**
     * A 403 on `/` is the webserver saying it has no document for this
     * directory. Until this check existed it read as a healthy application:
     * 4xx is under the server-error threshold, so the port probe passed it and
     * the sweep recorded `serving: ok` for a site nobody could open.
     */
    public function test_a_forbidden_front_page_is_an_error_and_names_the_docroot(): void
    {
        file_put_contents($this->dir . '/public/index.php', '<?php echo 1;');

        $report = $this->probe(PlatformManifest::RUNTIME_PHP, 403, '<title>403 Forbidden</title>');
        $check = $this->check($report, 'entry-served');

        $this->assertSame('missing_entry', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity']);
        $this->assertStringContainsString('public/index.php', (string) $check['detail']);
        $this->assertStringContainsString('docroot: public', (string) $check['fix']);
    }

    /** The file is where the server is looking, so it is not the file. */
    public function test_a_forbidden_front_page_with_an_index_at_the_root_blames_permissions(): void
    {
        rmdir($this->dir . '/public');
        file_put_contents($this->dir . '/index.php', '<?php echo 1;');

        $check = $this->check(
            $this->probe(PlatformManifest::RUNTIME_PHP, 403, 'Forbidden'),
            'entry-served'
        );

        $this->assertStringContainsString('cannot be read', (string) $check['detail']);
    }

    /** Dotclear: an empty public/ was served, and the root index is fine. */
    public function test_an_empty_public_beside_a_root_index_is_not_a_permissions_problem(): void
    {
        file_put_contents($this->dir . '/index.php', '<?php echo 1;');

        $check = $this->check(
            $this->probe(PlatformManifest::RUNTIME_PHP, 403, 'Forbidden'),
            'entry-served'
        );

        $this->assertStringContainsString('the document root has no index file', strtolower((string) $check['detail']));
        $this->assertStringNotContainsString('cannot be read', (string) $check['detail']);
    }

    /** A library checked out as though it were a site: nothing to serve. */
    public function test_a_project_with_no_entry_point_at_all_is_told_so(): void
    {
        $check = $this->check(
            $this->probe(PlatformManifest::RUNTIME_PHP, 403, 'Forbidden'),
            'entry-served'
        );

        $this->assertStringContainsString('no index.php anywhere', (string) $check['detail']);
    }

    /**
     * The narrowness is the design. A front controller answering 404 for `/`
     * has made a routing decision, and an API serving everything under /api is
     * working -- so the PHP check asks only about 403, where the application
     * never got a say. The static sibling has no router to defer to and keeps
     * the harder rule.
     */
    public function test_a_404_is_a_finding_for_a_static_site_and_not_for_php(): void
    {
        $php = $this->check($this->probe(PlatformManifest::RUNTIME_PHP, 404, 'Not Found'), 'entry-served');
        $static = $this->check($this->probe(PlatformManifest::RUNTIME_NGINX, 404, 'Not Found'), 'entry-served');

        $this->assertSame(CheckResult::STATUS_PASS, $php['status']);
        $this->assertSame(CheckResult::STATUS_FAIL, $static['status']);
    }

    /** A PHP app never renders these, so it is not asked about them. */
    public function test_the_php_group_does_not_bring_the_nginx_checks(): void
    {
        $ids = array_column($this->probe(PlatformManifest::RUNTIME_PHP, 200, 'ok')['checks'], 'id');

        $this->assertContains('php-executes', $ids);
        $this->assertNotContains('not-a-framework-default-page', $ids);
    }

    // ---- node --------------------------------------------------------

    public function test_a_node_app_that_works_passes_its_group(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_NODE, 200, '<h1>Express is serving</h1>');

        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
        foreach ($report['checks'] as $check) {
            $this->assertSame(CheckResult::STATUS_PASS, $check['status'], $check['id']);
        }
    }

    public function test_a_missing_module_stack_trace_is_an_error(): void
    {
        $report = $this->probe(
            PlatformManifest::RUNTIME_NODE,
            200,
            "Error: Cannot find module 'express'\n    at Module._compile (node:internal/modules)"
        );

        $this->assertSame('error_page', $report['serving']);
        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'no-stack-trace')['status']);
    }

    /**
     * `npm run dev` as a start command: it answers 200, it serves the
     * application, and it is not what anyone meant to put on the internet.
     */
    public function test_a_dev_server_is_a_warning_that_names_the_start_script(): void
    {
        file_put_contents($this->dir . '/package.json', json_encode([
            'name' => 'app',
            'scripts' => ['dev' => 'next dev', 'build' => 'next build', 'start' => 'next dev'],
        ]));

        $report = $this->probe(
            PlatformManifest::RUNTIME_NODE,
            200,
            '<script src="/_next/webpack-hmr"></script><h1>App</h1>'
        );
        $check = $this->check($report, 'not-a-dev-server');

        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_WARNING, $check['severity']);
        $this->assertSame('dev_server', $report['serving']);
        $this->assertStringContainsString('next dev', (string) $check['detail']);
        $this->assertStringContainsString('next build', (string) $check['fix']);
    }

    public function test_a_vite_dev_server_is_recognised_too(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_NODE, 200, '<script type="module" src="/@vite/client"></script>');

        $this->assertSame(CheckResult::STATUS_FAIL, $this->check($report, 'not-a-dev-server')['status']);
    }

    /**
     * An API served entirely under a prefix legitimately has no `/`, which is
     * why this is a warning and not a failed deploy.
     */
    public function test_express_with_no_root_route_is_only_a_warning(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_NODE, 404, '<pre>Cannot GET /</pre>');
        $check = $this->check($report, 'no-missing-root-route');

        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_WARNING, $check['severity']);
        $this->assertSame('no_root_route', $report['serving']);
    }

    /** A 404 alone is not a finding: only Express's own body makes it one. */
    public function test_a_plain_404_from_a_node_app_is_not_reported(): void
    {
        $report = $this->probe(PlatformManifest::RUNTIME_NODE, 404, 'Not Found');

        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
    }
}

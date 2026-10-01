<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * PHP Server Monitor without config.php: a suppressed fatal ends every request
 * with 200 and zero bytes, and every check passed it as serving: ok.
 */
class BlankPageCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
    }

    /** @return array{serving: string, checks: list<array<string, mixed>>} */
    private static function probe(string $runtime, int $status, string $body): array
    {
        $front = new ProbedResponse($status, $body, 'http://127.0.0.1:8000/');
        $verdict = AppHealth::checkRunnerFor([AppHealth::DETAIL_RUNTIME => $runtime])
            ->runByPath(['/' => $front], sys_get_temp_dir());

        return AppHealth::withBlankPageCheck($verdict, $runtime, $front);
    }

    /** @param array{checks: list<array<string, mixed>>} $report */
    private static function blank(array $report): ?array
    {
        foreach ($report['checks'] as $check) {
            if ($check['id'] === AppHealth::CHECK_BLANK_PAGE) {
                return $check;
            }
        }

        return null;
    }

    public function test_an_empty_php_front_page_is_not_serving(): void
    {
        $report = self::probe(PlatformManifest::RUNTIME_PHP, 200, '');

        $this->assertSame('blank_page', $report['serving']);
        $check = self::blank($report);
        $this->assertNotNull($check);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity']);
        $this->assertStringContainsString('200 with an empty page', $check['title']);
    }

    public function test_a_whitespace_only_php_front_page_is_empty_too(): void
    {
        $report = self::probe(PlatformManifest::RUNTIME_PHP, 200, "\n  \n");

        $this->assertSame('blank_page', $report['serving']);
        $this->assertNotNull(self::blank($report));
    }

    public function test_a_php_page_with_content_is_left_alone(): void
    {
        $report = self::probe(PlatformManifest::RUNTIME_PHP, 200, '<html><title>Login</title></html>');

        $this->assertSame(CheckRunner::SERVING_OK, $report['serving']);
        $this->assertNull(self::blank($report));
    }

    public function test_an_empty_redirect_or_error_from_php_is_not_a_blank_page(): void
    {
        foreach ([302, 404, 500] as $status) {
            $this->assertNull(self::blank(self::probe(PlatformManifest::RUNTIME_PHP, $status, '')), (string) $status);
        }
    }

    /** An API or a status port may answer an empty 200 on purpose. */
    public function test_other_runtimes_are_not_asked(): void
    {
        foreach ([PlatformManifest::RUNTIME_NODE, PlatformManifest::RUNTIME_COMPOSE, PlatformManifest::RUNTIME_COMMAND] as $runtime) {
            $report = self::probe($runtime, 200, '');

            $this->assertSame(CheckRunner::SERVING_OK, $report['serving'], $runtime);
            $this->assertNull(self::blank($report), $runtime);
        }
    }

    public function test_an_error_another_check_already_found_keeps_its_word(): void
    {
        $verdict = ['serving' => 'php_error', 'checks' => [[
            'id' => 'no-fatal-error',
            'status' => CheckResult::STATUS_FAIL,
            'severity' => HealthCheck::SEVERITY_ERROR,
        ]]];

        $report = AppHealth::withBlankPageCheck($verdict, PlatformManifest::RUNTIME_PHP, new ProbedResponse(200, '', 'http://127.0.0.1:8000/'));

        $this->assertSame('php_error', $report['serving']);
        $this->assertNotNull(self::blank($report));
    }
}

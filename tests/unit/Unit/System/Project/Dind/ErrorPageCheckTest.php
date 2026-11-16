<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ErrorPageCheck;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\TestNetwork;

/**
 * Roundcube without a working config answered every path with its own "Internal Error" page and a 200, and the
 * probe reported serving: ok, so the deploy and the supported-apps suite both counted it as working.
 */
class ErrorPageCheckTest extends TestCase
{
    private const ROUNDCUBE_INTERNAL_ERROR = '<!DOCTYPE html><html lang="en"><head><meta http-equiv="content-type" '
        .'content="text/html; charset=UTF-8"><title>Internal Error</title><style>body{font-family:sans-serif;'
        .'color:#333;background:#f4f4f4}div{max-width:600px;margin:15vh auto;padding:2em;background:#fff}</style>'
        .'</head><body><div><h1>Oops... something went wrong!</h1><p>An internal error has occurred. Your request '
        .'cannot be processed at this time.</p><p class="admin">For administrators: Please check the application '
        .'and/or server error logs for more information.</p></div></body></html>';

    private const LOGIN_WITH_ERROR_TEXT = '<!DOCTYPE html><html><head><title>Roundcube Webmail :: Welcome to '
        .'Roundcube Webmail</title></head><body><form method="post"><div class="error" hidden>Login failed: '
        .'server error</div><input name="_user"><input name="_pass" type="password"><button>Login</button>'
        .'</form><p>If an error occurs, contact your administrator.</p></body></html>';

    private const ERROR_LOG_VIEWER = '<!DOCTYPE html><html><head><title>Error log viewer</title></head><body>'
        .'<h1>Internal Server Error entries</h1><table><tr><td>500</td><td>Internal Error</td></tr></table>'
        .'</body></html>';

    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
    }

    /** @return array{serving: string, checks: list<array<string, mixed>>} */
    private static function probe(int $status, string $body, string $runtime = PlatformManifest::RUNTIME_PHP): array
    {
        $front = new ProbedResponse($status, $body, 'http://'.TestNetwork::LOOPBACK.':8000/');
        $verdict = AppHealth::checkRunnerFor([AppHealth::DETAIL_RUNTIME => $runtime])
            ->runByPath(['/' => $front], sys_get_temp_dir());

        return ErrorPageCheck::apply(AppHealth::withBlankPageCheck($verdict, $runtime, $front), $front);
    }

    /** @param array{checks: list<array<string, mixed>>} $report */
    private static function errorPage(array $report): ?array
    {
        foreach ($report['checks'] as $check) {
            if ($check['id'] === ErrorPageCheck::ID) {
                return $check;
            }
        }

        return null;
    }

    private static function page(string $title): string
    {
        return "<!DOCTYPE html><html><head><title>{$title}</title></head><body><p>Sorry.</p></body></html>";
    }

    public function test_roundcubes_internal_error_page_is_not_serving(): void
    {
        $report = self::probe(200, self::ROUNDCUBE_INTERNAL_ERROR);

        $this->assertSame('error_page', $report['serving']);
        $check = self::errorPage($report);
        $this->assertNotNull($check);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity']);
        $this->assertStringContainsString('"Internal Error"', $check['detail']);
        $this->assertSame('Internal Error', $check['evidence']['title']);
    }

    public function test_the_deploy_ends_with_the_failure_as_it_does_for_a_placeholder(): void
    {
        $failures = (new ReflectionMethod(AppHealth::class, 'failuresOf'))
            ->invoke(null, self::probe(200, self::ROUNDCUBE_INTERNAL_ERROR));

        $warnings = AppHealth::servingWarnings([AppHealth::DETAIL_CHECKS => $failures]);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("the application's own error page", $warnings[0]->message);
    }

    public function test_generic_error_titles_count_in_every_runtime(): void
    {
        $titles = [
            'Internal Server Error', 'Server Error', 'Application Error', 'Application error', 'Whoops!',
            'Whoops, looks like something went wrong.', 'Whoops! There was an error.', '500 Internal Server Error',
            '500', 'Error 500', 'HTTP Error 500', '  internal&nbsp;error  ', 'Unexpected error',
        ];
        foreach ([PlatformManifest::RUNTIME_PHP, 'node', 'command'] as $runtime) {
            foreach ($titles as $title) {
                $report = self::probe(200, self::page($title), $runtime);
                $this->assertSame('error_page', $report['serving'], "{$title} ({$runtime})");
            }
        }
    }

    public function test_real_pages_that_mention_errors_are_serving(): void
    {
        $pages = [
            'login with error text' => self::LOGIN_WITH_ERROR_TEXT,
            'error log viewer' => self::ERROR_LOG_VIEWER,
            '500px' => self::page('500px'),
            '500 Startups' => self::page('500 Startups'),
            'Whoops Tracker' => self::page('Whoops Tracker'),
            'branded error' => self::page('Server Error Monitor'),
            'no title' => '<p>Internal Error</p>',
        ];
        foreach ($pages as $name => $body) {
            $report = self::probe(200, $body);
            $this->assertSame('ok', $report['serving'], $name);
            $this->assertNull(self::errorPage($report), $name);
        }
    }

    public function test_a_long_page_or_another_status_is_left_to_the_other_checks(): void
    {
        $long = self::page('Internal Error').str_repeat(' ', ProbedResponse::SAMPLE_BYTES);
        $this->assertNull(self::errorPage(self::probe(200, $long)));

        $notFound = self::probe(404, self::page('Internal Error'));
        $this->assertNull(self::errorPage($notFound));

        $fiveHundred = self::probe(500, self::page('Internal Server Error'));
        $this->assertNull(self::errorPage($fiveHundred));
        $this->assertSame('error_page', $fiveHundred['serving']);
    }

    public function test_an_error_already_found_keeps_its_serving_word(): void
    {
        $body = '<html><head><title>Server Error</title></head><body><b>Fatal error</b>: boom</body></html>';

        $report = self::probe(200, $body);

        $this->assertSame('php_error', $report['serving']);
        $this->assertNotNull(self::errorPage($report));
    }
}

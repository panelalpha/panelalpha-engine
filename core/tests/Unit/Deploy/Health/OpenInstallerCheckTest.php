<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckException;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `_baseline/not-an-open-installer` against real pages.
 *
 * Every fixture is the first ProbedResponse::SAMPLE_BYTES of a page captured
 * on 2026-09-24 from the application's own image: dolibarr/dolibarr 24.0.0,
 * wordpress:php8.3-apache, ckulka/baikal:nginx 0.10.1, nextcloud:apache 35.
 * The paths are where the probe lands after following the redirect from `/`.
 */
class OpenInstallerCheckTest extends TestCase
{
    private const CHECK = 'not-an-open-installer';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/installers/' . $name);
    }

    /** @return array{serving: string, result: CheckResult} */
    private function ask(string $fixture, string $path, int $status = 200, string $check = self::CHECK): array
    {
        $response = new ProbedResponse($status, $this->fixture($fixture), 'http://127.0.0.1:8000' . $path, 0.05, $path);
        $runner = CheckRunner::for(null);
        foreach ($runner->results($response, null) as $result) {
            if ($result->check->id === $check) {
                return ['serving' => $runner->run($response, null)['serving'], 'result' => $result];
            }
        }
        $this->fail('the baseline has no ' . $check . ' check');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function openInstallers(): array
    {
        return [
            // `/` answers `Location: install/index.php`.
            'Dolibarr, no conf.php' => ['dolibarr-install.html', '/install/index.php'],
            'WordPress, language step' => ['wordpress-install.html', '/wp-admin/install.php'],
            'WordPress, admin account step' => ['wordpress-install-step1.html', '/wp-admin/install.php?step=1'],
            'WordPress, no wp-config.php' => ['wordpress-setup-config.html', '/wp-admin/setup-config.php'],
            'Baikal, admin password step' => ['baikal-install.html', '/admin/install/'],
            'Baikal, database step' => ['baikal-install-database.html', '/admin/install/'],
        ];
    }

    #[DataProvider('openInstallers')]
    public function test_an_unfinished_installer_is_reported(string $fixture, string $path): void
    {
        $asked = $this->ask($fixture, $path);

        $this->assertTrue($asked['result']->failed(), "{$fixture} at {$path} must be reported");
        $this->assertSame('unclaimed_install', $asked['serving']);
        $this->assertSame(HealthCheck::SEVERITY_WARNING, $asked['result']->check->severity);
        $this->assertSame('http://127.0.0.1:8000' . $path, $asked['result']->toArray()['evidence']['url']);
    }

    /**
     * Pages that must never fire. The first four sit at an installer path,
     * so the landing gate lets them through and only the markers decide.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function notInstallers(): array
    {
        return [
            'WordPress, installer after the install' => ['wordpress-already-installed.html', '/wp-admin/install.php', 200],
            'WordPress, setup-config with a config' => ['wordpress-setup-config-exists.html', '/wp-admin/setup-config.php', 409],
            'Dolibarr, installer behind install.lock' => ['dolibarr-install-locked.html', '/install/index.php', 200],
            'Baikal, installer after the install' => ['baikal-install-completed.html', '/admin/install/', 200],
            'WordPress login' => ['wordpress-login.html', '/wp-login.php', 200],
            'Dolibarr login' => ['dolibarr-login.html', '/', 200],
            'Baikal admin login' => ['baikal-admin-login.html', '/admin/', 200],
            'Nextcloud login' => ['nextcloud-login.html', '/login', 200],
        ];
    }

    #[DataProvider('notInstallers')]
    public function test_a_finished_install_or_a_login_form_is_not_reported(string $fixture, string $path, int $status): void
    {
        $asked = $this->ask($fixture, $path, $status);

        $this->assertFalse($asked['result']->failed(), "{$fixture} at {$path} is not an open installer");
        $this->assertNotSame('unclaimed_install', $asked['serving']);
    }

    /**
     * An installer served at `/` itself is outside the first version: the
     * narrow rule needs the redirect to name an installer path.
     */
    public function test_installer_markers_at_the_root_are_not_asked(): void
    {
        $this->assertFalse($this->ask('dolibarr-install.html', '/')['result']->failed());
    }

    /**
     * Installers that answer `/` with a 200 and no redirect, captured on
     * 2026-10-02 from matomo:apache 5.14.0 and nextcloud:apache 35.0.1 with no
     * configuration. The landing-gated check above never asks them.
     *
     * @return array<string, array{0: string}>
     */
    public static function installersAtTheRoot(): array
    {
        return [
            'Matomo, no config.ini.php' => ['matomo-install.html'],
            'Nextcloud, no administrator yet' => ['nextcloud-install.html'],
        ];
    }

    #[DataProvider('installersAtTheRoot')]
    public function test_an_installer_served_at_the_root_is_reported(string $fixture): void
    {
        $asked = $this->ask($fixture, '/', 200, 'not-an-open-installer-at-root');

        $this->assertTrue($asked['result']->failed(), "{$fixture} at / must be reported");
        $this->assertSame('unclaimed_install', $asked['serving']);
        $this->assertSame(HealthCheck::SEVERITY_WARNING, $asked['result']->check->severity);
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function everyPageThatIsNotAnInstaller(): array
    {
        return self::notInstallers() + [
            'Dolibarr installer markers' => ['dolibarr-install.html', '/', 200],
            'WordPress installer markers' => ['wordpress-install.html', '/', 200],
        ];
    }

    /** The root check has no landing gate, so its markers must not match anything else. */
    #[DataProvider('everyPageThatIsNotAnInstaller')]
    public function test_the_root_check_ignores_every_other_page(string $fixture, string $path, int $status): void
    {
        $this->assertFalse($this->ask($fixture, $path, $status, 'not-an-open-installer-at-root')['result']->failed());
    }

    /** A warning puts nothing in the warnings that make a deploy partial. */
    public function test_it_does_not_make_the_deploy_partial(): void
    {
        $result = $this->ask('dolibarr-install.html', '/install/index.php')['result'];
        $details = [
            AppHealth::DETAIL_CHECKS => [[
                'id' => $result->check->id,
                'severity' => $result->check->severity,
                'message' => (string) $result->title,
            ]],
        ];

        $this->assertSame([], AppHealth::servingWarnings($details));
        $this->assertCount(1, AppHealth::failedChecks($details), 'the sweep still lists it');
    }

    public function test_landing_must_be_a_list_of_local_paths(): void
    {
        foreach ([[], ['install'], ['/in stall'], 'install', ['a' => '/install']] as $bad) {
            try {
                HealthCheck::fromArray([
                    'id' => 'x',
                    'message' => 'x',
                    'landing' => $bad,
                    'expect' => ['body_not' => ['x']],
                ], 'test', 'test/x.yaml');
                $this->fail('accepted landing ' . json_encode($bad));
            } catch (CheckException $e) {
                $this->assertStringContainsString("'landing'", $e->getMessage());
            }
        }
    }

    public function test_landing_matches_by_prefix_without_the_query(): void
    {
        $at = static fn (string $path): ProbedResponse => new ProbedResponse(200, '', '', 0.0, $path);

        $this->assertTrue($at('/install/index.php?lang=en')->landedUnder(['/install']));
        $this->assertTrue($at('/Admin/Install/')->landedUnder(['/admin/install']));
        $this->assertFalse($at('/login?next=/install')->landedUnder(['/install']));
        $this->assertFalse($at('/')->landedUnder(['/install']));
    }
}

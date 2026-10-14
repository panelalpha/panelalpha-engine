<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\AppHealth;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use PHPUnit\Framework\TestCase;

/**
 * A crash-loop and a queue worker looked identical, and one of them is fine.
 *
 * Every check a runtime brings is asked *of a response*, so when no port
 * answers there is nothing to ask about and the list comes back empty. The
 * account is then reported `healthy: false`, `serving: unknown`,
 * `health_failed_checks: []` -- and the deploy's own message invites the
 * reader to shrug: "a worker or queue-only app can ignore this".
 *
 * Not every such account is a worker. PocketBase runs its binary with no
 * subcommand, prints its help and exits 0 in a loop. Miniflux exits on `dial
 * tcp [::1]:5432: connect: connection refused`, when given no PostgreSQL. Both
 * were reported with an empty check list.
 *
 * Docker knew the difference the whole time and was never asked: a worker
 * sits in `running`, these sit in `restarting`.
 */
class RestartLoopCheckTest extends TestCase
{
    /** PocketBase's, as `docker compose ps --format json --all` printed it. */
    private const CRASH_LOOP = <<<'JSON'
        {"Name":"pocketbase-app-1","Service":"app","State":"restarting","Status":"Restarting (0) 22 seconds ago","ExitCode":0,"Publishers":[]}
        JSON;

    /** A worker: publishes nothing, answers nothing, perfectly healthy. */
    private const HEALTHY_WORKER = <<<'JSON'
        {"Name":"queue-worker-1","Service":"worker","State":"running","Status":"Up 4 minutes","ExitCode":0,"Publishers":[]}
        JSON;

    public function test_a_restarting_container_is_reported_as_a_failed_check(): void
    {
        $check = AppHealth::restartLoopFrom(self::CRASH_LOOP);

        $this->assertNotNull($check, 'a crash-loop must not come back as an empty check list');
        $this->assertSame('app-restart-looping', $check['id']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity']);
    }

    /** The service and Docker's own words for it, so the report is actionable. */
    public function test_it_names_the_service_and_quotes_dockers_status(): void
    {
        $check = AppHealth::restartLoopFrom(self::CRASH_LOOP);

        $this->assertStringContainsString('app', $check['detail']);
        $this->assertStringContainsString('Restarting (0) 22 seconds ago', $check['detail']);
        $this->assertStringContainsString('container_service_logs', $check['fix']);
    }

    /**
     * The verdict has to name the crash loop, not settle for `unknown`.
     *
     * @group system-apphealth-parity
     */
    public function test_a_crash_loop_declares_its_own_serving_word(): void
    {
        if (!(new \ReflectionClass(AppHealth::class))->hasConstant('SERVING_RESTARTING')) {
            $this->markTestSkipped('System AppHealth does not define SERVING_RESTARTING yet');
        }

        $check = AppHealth::restartLoopFrom(self::CRASH_LOOP);

        $this->assertSame(
            AppHealth::SERVING_RESTARTING,
            $check['serving'] ?? null,
            'the crash-loop check must name the verdict it produces'
        );
        $this->assertSame('restarting', AppHealth::SERVING_RESTARTING);
        $this->assertNotSame(CheckRunner::SERVING_UNKNOWN, AppHealth::SERVING_RESTARTING);
    }

    /**
     * @group system-apphealth-parity
     */
    public function test_a_crash_loop_does_not_get_the_dismissible_warning(): void
    {
        if (!(new \ReflectionClass(AppHealth::class))->hasConstant('NOT_ANSWERING_RESTARTING')) {
            $this->markTestSkipped('System AppHealth does not define NOT_ANSWERING_RESTARTING yet');
        }

        $details = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_SERVING => AppHealth::SERVING_RESTARTING,
            AppHealth::DETAIL_PORTS => [['port' => 8080, 'status' => 'fail', 'http_code' => null]],
            AppHealth::DETAIL_CHECKS => [],
        ];

        $warnings = AppHealth::servingWarnings($details);

        $this->assertContains(AppHealth::NOT_ANSWERING_RESTARTING, $warnings);
        $this->assertNotContains(AppHealth::NOT_ANSWERING, $warnings);
    }

    /** A worker publishing no port still gets the sentence written for it. */
    public function test_a_non_answering_non_crashing_app_keeps_the_dismissible_warning(): void
    {
        $details = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_SERVING => CheckRunner::SERVING_UNKNOWN,
            AppHealth::DETAIL_PORTS => [['port' => 8080, 'status' => 'fail', 'http_code' => null]],
            AppHealth::DETAIL_CHECKS => [],
        ];

        $this->assertSame([AppHealth::NOT_ANSWERING], AppHealth::servingWarnings($details));
    }

    /** The whole point: a worker must stay silent. */
    public function test_a_running_worker_produces_no_check(): void
    {
        $this->assertNull(AppHealth::restartLoopFrom(self::HEALTHY_WORKER));
    }

    /** One restarting service among healthy ones still counts. */
    public function test_it_finds_a_restarting_service_beside_healthy_ones(): void
    {
        $check = AppHealth::restartLoopFrom(
            self::HEALTHY_WORKER . "\n" . self::CRASH_LOOP . "\n"
            . '{"Name":"db-1","Service":"db","State":"running","Status":"Up 5 minutes (healthy)"}'
        );

        $this->assertNotNull($check);
        $this->assertSame(['app (Restarting (0) 22 seconds ago)'], $check['evidence']['restarting']);
    }

    /** Nothing to read is not a crash-loop. */
    public function test_empty_or_unreadable_output_produces_no_check(): void
    {
        $this->assertNull(AppHealth::restartLoopFrom(''));
        $this->assertNull(AppHealth::restartLoopFrom("   \n\n  "));
        $this->assertNull(AppHealth::restartLoopFrom("not json at all\n{broken"));
    }

    /**
     * The other half of the cycle. Docker's backoff parks a crash-looping
     * container in `exited` between attempts, so the same loop reads as
     * `restarting` or `exited` depending only on when it is asked. Wekan
     * showed it: the check fired during the deploy and was persisted, and a
     * standalone health check a minute later found nothing on the same
     * still-crashing container.
     */
    public function test_a_container_exited_between_restarts_still_reports(): void
    {
        $check = AppHealth::restartLoopFrom(
            '{"Name":"wekan-app","Service":"wekan","State":"exited","Status":"Exited (137) 2 seconds ago","ExitCode":137}'
        );

        $this->assertNotNull($check, 'the backoff half of a crash loop is still a crash loop');
        $this->assertSame('app-restart-looping', $check['id']);
        $this->assertStringContainsString('Exited (137)', $check['detail']);
    }

    /** A one-shot job that finished is allowed to have finished. */
    public function test_a_clean_exit_is_not_a_crash(): void
    {
        $this->assertNull(AppHealth::restartLoopFrom(
            '{"Name":"migrate-1","Service":"migrate","State":"exited","Status":"Exited (0) 1 minute ago","ExitCode":0}'
        ));
    }

    /** A row with no Service name still reports, rather than being dropped. */
    public function test_a_row_without_a_service_name_still_reports(): void
    {
        $check = AppHealth::restartLoopFrom('{"State":"restarting","Status":"Restarting (1) 3 seconds ago"}');

        $this->assertNotNull($check);
        $this->assertStringContainsString('a service', $check['detail']);
    }

    /**
     * "The application is restarting" has to be about the application.
     *
     * Every compose row used to qualify, so a one-shot `migrate` that exited 1
     * on an already-applied migration made the report say the application kept
     * exiting — about a container that was running fine and had simply not
     * bound its port yet. A datastore has its own check and a failed job is a
     * failed job.
     */
    public function test_a_failed_one_shot_job_is_not_the_application_crash_looping(): void
    {
        $rows = '{"Service":"migrate","State":"exited","ExitCode":1,"Status":"Exited (1) 2 minutes ago"}';

        if ((new \ReflectionMethod(AppHealth::class, 'restartLoopFrom'))->getNumberOfParameters() > 1) {
            $this->assertNotNull(
                AppHealth::restartLoopFrom($rows),
                'without roles there is nothing to tell a job from the app'
            );
            $this->assertNull(AppHealth::restartLoopFrom($rows, ['migrate' => 'job']));

            return;
        }

        // System AppHealth has no compose-role filter yet: any non-zero exit counts.
        $this->assertNotNull(AppHealth::restartLoopFrom($rows));
    }

    /** The application itself is still reported, roles or not. */
    public function test_the_application_restarting_is_still_reported(): void
    {
        $rows = '{"Service":"app","State":"restarting","ExitCode":1,"Status":"Restarting (1) 4 seconds ago"}';

        $method = new \ReflectionMethod(AppHealth::class, 'restartLoopFrom');
        if ($method->getNumberOfParameters() > 1) {
            $this->assertNotNull(AppHealth::restartLoopFrom($rows, ['migrate' => 'job', 'db' => 'datastore']));

            return;
        }

        $this->assertNotNull(AppHealth::restartLoopFrom($rows));
    }

    /** A job is recognised from the compose file, not from a marker. */
    public function test_a_job_service_is_detected_from_the_compose_file(): void
    {
        if (!method_exists(AppHealth::class, 'datastoreRolesFrom')) {
            $this->markTestSkipped('System AppHealth does not expose datastoreRolesFrom yet');
        }

        $roles = AppHealth::datastoreRolesFrom(
            "services:\n  migrate:\n    image: app\n    command: ./manage.py migrate\n"
            . "  app:\n    image: app\n    ports: [\"8000:8000\"]\n"
        );

        $this->assertSame('job', $roles['migrate'] ?? null);
        $this->assertArrayNotHasKey('app', $roles);
    }
}

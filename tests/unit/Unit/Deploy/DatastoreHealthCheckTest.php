<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\AppHealth;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\HealthCheck;
use PHPUnit\Framework\TestCase;

/**
 * An application that answers 200 while its database is down.
 *
 * This is the case the whole check exists for, and it is not hypothetical:
 * market-radar serves an SPA shell at `/` and reports its real condition at
 * `/api/health`, so every baseline check passed on a deploy whose MariaDB was
 * unreachable. The page was fine. The application was not.
 *
 * Docker had the answer and was already being asked -- `restartLoopCheck()`
 * runs `docker compose ps --format json --all` and reads one field out of it.
 * This reads `Health` from the same output, which a service populates only
 * when it declares a healthcheck of its own.
 */
class DatastoreHealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!method_exists(AppHealth::class, 'datastoreHealthFrom')) {
            $this->markTestSkipped('System AppHealth does not expose datastore health helpers yet');
        }
    }

    /** market-radar's own services, as compose prints them. */
    private const UNHEALTHY_MARIADB = <<<'JSON'
        {"Name":"project-mariadb-1","Service":"mariadb","State":"running","Status":"Up 2 minutes (unhealthy)","Health":"unhealthy","ExitCode":0,"Publishers":[{"URL":"0.0.0.0","TargetPort":3306,"PublishedPort":0}]}
        {"Name":"project-app-1","Service":"app","State":"running","Status":"Up 2 minutes","ExitCode":0,"Publishers":[{"URL":"0.0.0.0","TargetPort":4000,"PublishedPort":4000}]}
        JSON;

    /** The same stack, healthy: the application's own shape on a good day. */
    private const HEALTHY = <<<'JSON'
        {"Name":"project-mariadb-1","Service":"mariadb","State":"running","Status":"Up 2 minutes (healthy)","Health":"healthy","ExitCode":0,"Publishers":[]}
        {"Name":"project-app-1","Service":"app","State":"running","Status":"Up 2 minutes","ExitCode":0,"Publishers":[]}
        JSON;

    /** A service with no healthcheck of its own: Docker prints no Health key. */
    private const NO_HEALTHCHECK = <<<'JSON'
        {"Name":"project-app-1","Service":"app","State":"running","Status":"Up 2 minutes","ExitCode":0,"Publishers":[]}
        JSON;

    public function test_an_unhealthy_service_is_reported_as_a_failed_check(): void
    {
        $check = AppHealth::datastoreHealthFrom(self::UNHEALTHY_MARIADB, ['mariadb' => 'datastore']);

        $this->assertNotNull($check, 'an unhealthy sidecar must not come back as no check at all');
        $this->assertSame('datastore-unhealthy', $check['id']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity']);
    }

    /** The service and its role, so the report says which dependency broke. */
    public function test_it_names_the_service_and_calls_a_database_a_database(): void
    {
        $check = AppHealth::datastoreHealthFrom(self::UNHEALTHY_MARIADB, ['mariadb' => 'datastore']);

        $this->assertStringContainsString('mariadb', $check['detail']);
        $this->assertStringContainsString('(the database)', $check['detail']);
        $this->assertStringContainsString('container_service_logs', $check['fix']);
        $this->assertSame(
            [['service' => 'mariadb', 'role' => 'datastore', 'reason' => 'it is failing its own healthcheck']],
            $check['evidence']['unhealthy']
        );
    }

    /**
     * The detail says "the application answered", because that is what makes
     * this different from the crash-loop check and what tells the reader where
     * to look.
     */
    public function test_it_says_the_application_itself_is_up(): void
    {
        $check = AppHealth::datastoreHealthFrom(self::UNHEALTHY_MARIADB, []);

        $this->assertStringContainsString('answered', $check['detail']);
        $this->assertStringNotContainsString('(the database)', $check['detail']);
    }

    public function test_a_healthy_stack_reports_nothing(): void
    {
        $this->assertNull(AppHealth::datastoreHealthFrom(self::HEALTHY, ['mariadb' => 'datastore']));
    }

    /** No healthcheck declared means Docker says nothing, and neither do we. */
    public function test_a_service_without_a_healthcheck_is_not_guessed_at(): void
    {
        $this->assertNull(AppHealth::datastoreHealthFrom(self::NO_HEALTHCHECK, []));
    }

    public function test_empty_output_reports_nothing(): void
    {
        $this->assertNull(AppHealth::datastoreHealthFrom('', []));
        $this->assertNull(AppHealth::datastoreHealthFrom("not json at all\n", []));
    }

    /**
     * Several services can be unwell at once, and the report names all of
     * them: fixing one and redeploying into another would be a second round
     * trip for information Docker already gave us.
     */
    public function test_every_unhealthy_service_is_named(): void
    {
        $two = self::UNHEALTHY_MARIADB . "\n" . '{"Name":"project-redis-1","Service":"redis","State":"running","Health":"unhealthy","ExitCode":0}';

        $check = AppHealth::datastoreHealthFrom($two, ['mariadb' => 'datastore']);

        $this->assertStringContainsString('mariadb', $check['detail']);
        $this->assertStringContainsString('redis', $check['detail']);
        $this->assertCount(2, $check['evidence']['unhealthy']);
    }

    /**
     * The role is only phrasing, so it must not be able to suppress a finding
     * Docker already gave us.
     */
    public function test_the_verdict_does_not_depend_on_the_role_lookup(): void
    {
        $withRole = AppHealth::datastoreHealthFrom(self::UNHEALTHY_MARIADB, ['mariadb' => 'datastore']);
        $without = AppHealth::datastoreHealthFrom(self::UNHEALTHY_MARIADB, []);

        $this->assertSame($withRole['id'], $without['id']);
        $this->assertSame($without['status'], $withRole['status']);
    }

    /** market-radar's compose services, so the role comes from the catalogue. */
    public function test_the_datastore_role_is_read_from_the_compose_file(): void
    {
        $compose = <<<'YAML'
        services:
          mariadb:
            image: mariadb:11
            ports:
              - "3306:3306"
          app:
            build: .
            ports:
              - "4000:4000"
        YAML;

        $roles = AppHealth::datastoreRolesFrom($compose);

        $this->assertSame('datastore', $roles['mariadb'] ?? null);
        $this->assertArrayNotHasKey('app', $roles);
    }

    public function test_a_compose_file_that_cannot_be_read_yields_no_roles(): void
    {
        $this->assertSame([], AppHealth::datastoreRolesFrom("\tthis: is: not: yaml\n- - -"));
    }

    /**
     * A dependency that declares no healthcheck was invisible.
     *
     * `Health` is absent for a service with no `healthcheck:` block, and
     * plenty of compose files have none — so a database that had exited or was
     * restarting produced no verdict here. The restart-loop check did not
     * cover it either: it returns early the moment any port answers. A
     * dependency that died behind a login page that still renders was reported
     * by neither, which is the shape this check exists for.
     */
    public function test_a_dependency_with_no_healthcheck_is_still_reported(): void
    {
        $exited = '{"Service":"db","State":"exited","ExitCode":1,"Status":"Exited (1) 30 seconds ago"}';
        $restarting = '{"Service":"db","State":"restarting","ExitCode":1,"Status":"Restarting (1) 3 seconds ago"}';

        $this->assertSame(
            'it exited with code 1',
            AppHealth::datastoreHealthFrom($exited, ['db' => 'datastore'])['evidence']['unhealthy'][0]['reason']
        );
        $this->assertSame(
            'it keeps restarting',
            AppHealth::datastoreHealthFrom($restarting, ['db' => 'datastore'])['evidence']['unhealthy'][0]['reason']
        );
    }

    /**
     * `starting` is Docker saying "inside start_period", which is not a
     * failure. `report()` runs straight after `up -d`, so a datastore that
     * takes 45s to initialise is legitimately mid-boot at probe time — and
     * calling that an error made clean deploys finish with warnings.
     */
    public function test_a_service_still_inside_its_start_period_is_not_a_failure(): void
    {
        $starting = '{"Service":"db","State":"running","Health":"starting","Status":"Up 8 seconds (health: starting)"}';

        $this->assertNull(AppHealth::datastoreHealthFrom($starting, ['db' => 'datastore']));
    }

    /** A one-shot job that finished is allowed to have finished. */
    public function test_a_clean_exit_is_not_a_failure(): void
    {
        $done = '{"Service":"migrate","State":"exited","ExitCode":0,"Status":"Exited (0) 1 minute ago"}';

        $this->assertNull(AppHealth::datastoreHealthFrom($done, []));
    }

    /**
     * A container can be unhealthy *and* restarting; "it keeps restarting" is
     * the more useful sentence, and the one that says it is not coming back.
     */
    public function test_restarting_outranks_unhealthy_in_the_reason(): void
    {
        $both = '{"Service":"db","State":"restarting","Health":"unhealthy","ExitCode":1}';

        $this->assertSame(
            'it keeps restarting',
            AppHealth::datastoreHealthFrom($both, ['db' => 'datastore'])['evidence']['unhealthy'][0]['reason']
        );
    }
}

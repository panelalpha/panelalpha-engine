<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\AppLauncher;
use PHPUnit\Framework\TestCase;

/**
 * A gate service that fails must fail the deploy.
 *
 * `docker compose up -d` runs without `--wait`, so it returns as soon as
 * containers are created and started and does not care what exit code a
 * one-shot service produces. A service that runs, refuses, and exits 1
 * therefore left `up -d` exiting 0 and the deploy reported successful.
 *
 * Measured on Manticore (supported-apps#297) by deliberately switching off
 * the authentication its gate exists to require: `ready` printed its refusal
 * and exited 1, `up -d` exited 0, the rebuild endpoint returned success, and
 * the account was published with an unauthenticated, writable search engine
 * on it. The failure is silent in the worst direction — the thing the gate
 * exists to prevent is exactly what ships.
 *
 * A gate is the only mechanism a recipe has to assert a post-condition
 * before the account goes live, and several rely on one: Manticore (#297),
 * Baikal (#274), Mattermost (#47), Dolibarr (#307).
 *
 * This is the narrower of the two fixes the issue offers. `--wait` also
 * waits on health checks, so it would start delaying or failing deploys that
 * pass today — worth measuring against real projects first, and not needed
 * here, because the exit codes are already there to read.
 */
class GateServiceFailsTheDeployTest extends TestCase
{
    /** Compose v2's array form. */
    private const PS_ARRAY = <<<'JSON'
        [
          {"Name":"proj-app-1","Service":"app","State":"running","ExitCode":0},
          {"Name":"proj-ready-1","Service":"ready","State":"exited","ExitCode":1}
        ]
        JSON;

    /** And its NDJSON form, which other versions write. */
    private const PS_LINES = <<<'JSON'
        {"Name":"proj-app-1","Service":"app","State":"running","ExitCode":0}
        {"Name":"proj-ready-1","Service":"ready","State":"exited","ExitCode":1}
        JSON;

    public function test_a_gate_that_refused_is_seen_in_either_output_shape(): void
    {
        foreach ([self::PS_ARRAY, self::PS_LINES] as $output) {
            $this->assertSame(['ready' => 1], AppLauncher::failedServices($output));
        }
    }

    /** A one-shot that did its job exited 0 and is not a failure. */
    public function test_a_migration_that_succeeded_is_not_a_failure(): void
    {
        $output = '[{"Name":"p-migrate-1","Service":"migrate","State":"exited","ExitCode":0},'
            . '{"Name":"p-app-1","Service":"app","State":"running","ExitCode":0}]';

        $this->assertSame([], AppLauncher::failedServices($output));
    }

    /**
     * Only `exited`. A service still running, restarting or merely created
     * has not reported anything yet, and calling one of those a failure would
     * be the same defect pointed the other way.
     */
    public function test_a_service_that_has_not_finished_is_not_judged(): void
    {
        $output = '[{"Service":"a","State":"running","ExitCode":0},'
            . '{"Service":"b","State":"restarting","ExitCode":1},'
            . '{"Service":"c","State":"created","ExitCode":0}]';

        $this->assertSame([], AppLauncher::failedServices($output));
    }

    public function test_every_failed_service_is_named_not_just_the_first(): void
    {
        $output = '[{"Service":"ready","State":"exited","ExitCode":1},'
            . '{"Service":"verified","State":"exited","ExitCode":3}]';

        $this->assertSame(['ready' => 1, 'verified' => 3], AppLauncher::failedServices($output));
    }

    /** Output this cannot read must not invent a failure. */
    public function test_unreadable_output_is_not_a_failure(): void
    {
        foreach (['', '   ', 'Error response from daemon', '{', 'null'] as $output) {
            $this->assertSame([], AppLauncher::failedServices($output));
        }
    }

    /** A container with no Service key still gets named by its container name. */
    public function test_a_row_without_a_service_name_falls_back_to_the_container(): void
    {
        $output = '[{"Name":"proj-ready-1","State":"exited","ExitCode":2}]';

        $this->assertSame(['proj-ready-1' => 2], AppLauncher::failedServices($output));
    }
}

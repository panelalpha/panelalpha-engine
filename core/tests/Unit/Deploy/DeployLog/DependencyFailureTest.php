<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DependencyFailure;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\FailureOutput;
use PHPUnit\Framework\TestCase;

/**
 * A dependency that never came up is reported by what it printed, not by
 * compose's `dependency failed to start` (engine#97).
 */
class DependencyFailureTest extends TestCase
{
    /** Limbas on postgres 18, from the issue's own payload. */
    private const COMPOSE_STDERR = <<<'TXT'
        time="2026-09-19T16:36:30Z" level=warning msg="/home/acct/project/docker-compose.yml: the attribute `version` is obsolete, it will be ignored, please remove it to avoid potential confusion"
         Container project-limbas_pgsql-1 Starting
         Container project-limbas_pgsql-1 Started
         Container project-limbas_pgsql-1 Waiting
         Container project-limbas_pgsql-1 Error dependency limbas_pgsql failed to start
        dependency failed to start: container project-limbas_pgsql-1 is unhealthy
        TXT;

    /** `docker logs project-limbas_pgsql-1`, the tail. */
    private const PGSQL_LOGS = <<<'TXT'
               upgrading the underlying database using "pg_upgrade" (which requires both
               versions).

        Error: in 18+, these Docker images are configured to store database data in a
               format which is compatible with "pg_ctlcluster" (specifically, using
               major-version-specific directory names).  This better reflects how
               PostgreSQL itself works, and how upgrades are to be performed.

               See also https://github.com/docker-library/postgres/pull/1259

               Counter to that, there appears to be PostgreSQL data in:
                 /var/lib/postgresql/data (unused mount/volume)
        TXT;

    public function test_the_container_compose_gave_up_on_is_named(): void
    {
        $this->assertSame(
            [['kind' => 'container', 'name' => 'project-limbas_pgsql-1', 'state' => 'unhealthy']],
            DependencyFailure::failed(self::COMPOSE_STDERR)
        );
    }

    public function test_an_exited_container_and_a_failed_one_shot_are_named_too(): void
    {
        $this->assertSame([
            ['kind' => 'container', 'name' => 'project-db-1', 'state' => 'exited with code 1'],
            ['kind' => 'service', 'name' => 'migrate', 'state' => 'exited with code 2'],
        ], DependencyFailure::failed(
            "dependency failed to start: container project-db-1 exited (1)\n"
            . 'service "migrate" didn\'t complete successfully: exit 2'
        ));
    }

    public function test_output_naming_no_dependency_names_none(): void
    {
        $this->assertSame([], DependencyFailure::failed("failed to solve: process \"/bin/sh -c npm ci\" did not complete successfully: exit code: 1\n"));
    }

    public function test_the_cause_starts_at_the_line_that_says_error(): void
    {
        $cause = DependencyFailure::cause(self::PGSQL_LOGS);

        $this->assertStringStartsWith('Error: in 18+, these Docker images are configured', $cause);
    }

    /** Compose's `name  | ` and docker's timestamps are not part of what it said. */
    public function test_log_prefixes_are_dropped(): void
    {
        $cause = DependencyFailure::cause(
            "db-1  | 2026-09-19T16:38:05.355051597Z 2026-09-19 16:38:05.355 UTC [1] LOG:  starting\n"
            . "db-1  | 2026-09-19T16:38:05.355074319Z 2026-09-19 16:38:05.356 UTC [1] FATAL:  data directory \"/var/lib/postgresql/data\" has wrong ownership\n"
        );

        $this->assertSame('2026-09-19 16:38:05.356 UTC [1] FATAL:  data directory "/var/lib/postgresql/data" has wrong ownership', $cause);
    }

    public function test_output_with_no_error_line_gives_its_last_lines(): void
    {
        $this->assertSame("a\nb\nc\nd", DependencyFailure::cause("a\nb\nc\nd\n"));
    }

    /**
     * End to end over the text the deploy reports from: the launcher puts the
     * line in front of compose's stderr, and the message leads with what
     * postgres said. Without it the message was compose's own line.
     */
    public function test_the_failure_message_quotes_the_dependency(): void
    {
        $line = DependencyFailure::describe('project-limbas_pgsql-1', 'unhealthy', DependencyFailure::cause(self::PGSQL_LOGS));
        $stderr = $line . "\n" . self::COMPOSE_STDERR;

        $message = DeployFailureExplainer::explain(FailureOutput::select($stderr));

        $this->assertNotNull($message);
        $this->assertStringContainsString('The service project-limbas_pgsql-1 did not start (unhealthy)', $message);
        $this->assertStringContainsString('Error: in 18+, these Docker images are configured to store database data in a format', $message);
        $this->assertSame('dependency-failed', DeployFailureExplainer::match($stderr)['rule']);

        $before = FailureOutput::select(self::COMPOSE_STDERR);
        $this->assertStringNotContainsString('18+', (string) DeployFailureExplainer::explain($before) . $before);
    }

    /** A one-shot that exited non-zero is not left to the generic exit-code sentence. */
    public function test_it_wins_over_the_generic_exit_code_rule(): void
    {
        $stderr = DependencyFailure::describe('migrate', 'exited with code 1', 'Error: relation "users" does not exist')
            . "\nservice \"migrate\" didn't complete successfully: exit 1";

        $this->assertSame('dependency-failed', DeployFailureExplainer::match($stderr)['rule']);
    }
}

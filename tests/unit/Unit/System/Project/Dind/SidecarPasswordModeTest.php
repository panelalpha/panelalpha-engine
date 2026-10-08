<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Sidecar\SidecarPasswords;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\RuntimeSidecars;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\AccountSecrets;
use PHPUnit\Framework\TestCase;

/**
 * Which password a sidecar nobody configured gets, decided once
 * per account from whether its Docker already holds data.
 */
class SidecarPasswordModeTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $frozen = [];

    /** @var list<string> */
    private array $commands = [];

    private function dind(array $details, ?string $volumes): Dind
    {
        $commands = &$this->commands;
        $system = new class ($volumes, $commands) extends System {
            /** @param list<string> $commands */
            public function __construct(private ?string $volumes, private array &$commands)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->commands[] = $line;
                if ($this->volumes === null) {
                    throw new \RuntimeException('Cannot connect to the Docker daemon');
                }

                return $this->volumes;
            }
        };

        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = $details;

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('username')->willReturn('acme');
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('freezeDeploySnapshot')->willReturnCallback(function (array $snapshot): void {
            $this->frozen[] = $snapshot;
        });
        $dind->method('strategy')->willReturn(new class ($dind) extends DeployStrategy {
            public function secrets(): AccountSecrets
            {
                return new class ($this) extends AccountSecrets {
                    public function __construct(object $unused)
                    {
                    }

                    public function for(string $purpose): string
                    {
                        return 'stub-seed:' . $purpose;
                    }
                };
            }
        });

        return $dind;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->frozen = [];
        $this->commands = [];
    }

    public function test_a_new_account_gets_derived_passwords_and_keeps_them(): void
    {
        $passwords = (new RuntimeSidecars($this->dind([], '')))->passwords();

        $this->assertFalse($passwords->isLegacy());
        $this->assertSame([[SidecarPasswords::DETAILS_KEY => 'derived']], $this->frozen);
        $this->assertStringContainsString("'docker' 'volume' 'ls' '-q'", implode("\n", $this->commands));
    }

    public function test_an_account_with_data_already_keeps_app(): void
    {
        $passwords = (new RuntimeSidecars($this->dind([], "project_dbdata\n")))->passwords();

        $this->assertTrue($passwords->isLegacy());
        $this->assertSame('app', $passwords->for('MYSQL_PASSWORD'));
        $this->assertSame([[SidecarPasswords::DETAILS_KEY => 'legacy']], $this->frozen);
    }

    public function test_the_stored_mode_is_used_without_asking_docker(): void
    {
        $passwords = (new RuntimeSidecars($this->dind([SidecarPasswords::DETAILS_KEY => 'derived'], "project_dbdata\n")))
            ->passwords();

        $this->assertFalse($passwords->isLegacy());
        $this->assertSame([], $this->frozen);
        $this->assertSame([], $this->commands);
    }

    public function test_an_unreachable_docker_means_legacy_and_nothing_stored(): void
    {
        $passwords = (new RuntimeSidecars($this->dind([], null)))->passwords();

        $this->assertTrue($passwords->isLegacy());
        $this->assertSame([], $this->frozen);
    }
}

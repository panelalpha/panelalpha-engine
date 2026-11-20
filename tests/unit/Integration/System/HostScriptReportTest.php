<?php

namespace Tests\Integration\System;

use App\Auth\TokenAbilities;
use App\System;
use App\System\HostScriptReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;

class HostScriptReportTest extends TestCase
{
    private const LINE = "tenant-network: the tenant firewall was not applied (pa-root-owned: '/opt/panelalpha/shared-hosting/scripts/tenant-network-firewall.sh' "
        .'could be written by someone other than root), so an account container Docker starts on its own gets no network until the engine starts it. '
        ."An engine update or a source deploy puts the engine's scripts back, writable by root alone.";

    /** @return iterable<string, array{string}> */
    public static function failedFiles(): iterable
    {
        yield 'the tenant firewall' => [HostScriptReport::TENANT_NETWORK_FIREWALL];
        yield 'the host firewall' => [HostScriptReport::HOST_FIREWALL];
    }

    #[DataProvider('failedFiles')]
    public function test_the_line_the_root_broker_logged_is_the_warning(string $failedFile): void
    {
        $system = $this->system(FakeProcess::ok(self::LINE."\n"));

        $report = (new HostScriptReport($system, $failedFile))->report();

        $this->assertSame(['failing' => true, 'warning' => self::LINE], $report);
        $this->assertCount(1, $system->ran);
        $this->assertSame('files.read', LocalRoot::action($system->ran[0]));
        $this->assertSame(['cat', '--', $failedFile], LocalRoot::plain($system->ran[0]));
    }

    public function test_no_record_means_no_warning(): void
    {
        $file = HostScriptReport::HOST_FIREWALL;
        $missing = $this->system(FakeProcess::failed("cat: '{$file}': No such file or directory"));
        $empty = $this->system(FakeProcess::ok("\n"));

        $this->assertSame(['failing' => false, 'warning' => null], (new HostScriptReport($missing, $file))->report());
        $this->assertSame(['failing' => false, 'warning' => null], (new HostScriptReport($empty, $file))->report());
    }

    public function test_a_token_scoped_to_projects_is_not_told_about_the_host(): void
    {
        $system = $this->system(FakeProcess::ok(self::LINE));
        $scoped = TokenAbilities::fromList(TokenAbilities::build(api: true, mcp: true, projects: ['alice']));
        $operator = TokenAbilities::fromList(TokenAbilities::build(api: true, mcp: true));
        $report = new HostScriptReport($system, HostScriptReport::TENANT_NETWORK_FIREWALL);

        $this->assertNull($report->reportFor($scoped));
        $this->assertSame([], $system->ran);
        $this->assertSame(self::LINE, $report->reportFor($operator)['warning'] ?? null);
    }

    private function system(Process $answer): System
    {
        return new class($answer) extends System
        {
            /** @var list<string|list<string>> */
            public array $ran = [];

            public function __construct(private Process $answer) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = $cmd;

                return $this->answer;
            }
        };
    }
}

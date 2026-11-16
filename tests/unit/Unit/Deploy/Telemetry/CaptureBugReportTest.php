<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\BugReport;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/**
 * Every answer captureBugReport() gives, word for word, including the ones
 * that need the project to exist.
 */
class CaptureBugReportTest extends TestCase
{
    use InMemoryDatabase;

    private const ENDPOINT = 'https://monitoring.test/api/v1/events';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $this->root = sys_get_temp_dir().'/pa-bugcap-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/outbox', 0777, true);
        file_put_contents($this->root.'/install-id', str_repeat('b', 32));

        config([
            'telemetry.enabled' => true,
            'telemetry.bug_reports.enabled' => true,
            'telemetry.tier' => 2,
            'telemetry.spool_dir' => $this->root.'/outbox',
            'telemetry.pin_file' => $this->root.'/install-id',
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
        ]);
        Telemetry::resetCache();
        $this->useSpool($this->root.'/outbox');
        (new ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, []);
        $this->makeUser('shop', ['deploy_strategy' => 'static', 'deploy_runtime' => 'nginx']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/outbox/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root.'/outbox');
        @unlink($this->root.'/install-id');
        @rmdir($this->root);
        Telemetry::resetCache();

        parent::tearDown();
    }

    private function useSpool(string $dir): void
    {
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, new Spool($dir, new RecordingRunner));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function file(array $overrides = []): array
    {
        return Telemetry::captureBugReport($overrides + [
            'project' => 'shop',
            'title' => 'Deploys succeed but the site answers 502',
            'description' => 'It finishes green and then 502s.',
            'severity' => 'high',
            'area' => 'deploy',
            'attach_log' => false,
            'attach_health' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function refusal(string $status, string $reason): array
    {
        return [
            'status' => $status,
            'queued' => false,
            'id' => null,
            'reason' => $reason,
            'endpoint' => self::ENDPOINT,
            'report' => null,
        ];
    }

    /** @return list<string> */
    private function spooled(): array
    {
        return glob($this->root.'/outbox/*.json') ?: [];
    }

    public function test_each_refusal_is_answered_word_for_word(): void
    {
        $this->assertSame(
            $this->refusal('invalid', 'A bug report needs both a title and a description.'),
            $this->file(['description' => '  '])
        );
        $this->assertSame(
            $this->refusal('invalid', 'A bug report is about one application: name the project it concerns.'),
            $this->file(['project' => ' '])
        );
        $this->assertSame(
            $this->refusal('no-project', "No project named 'ghost' on this install."),
            $this->file(['project' => ' ghost '])
        );

        config(['telemetry.bug_reports.enabled' => false]);
        $this->assertSame(
            $this->refusal('disabled', 'Bug reports are disabled on this install (TELEMETRY_BUG_REPORTS=false).'),
            $this->file()
        );

        config(['telemetry.enabled' => false]);
        $this->assertSame(
            $this->refusal(
                'disabled',
                'Telemetry is disabled on this install (TELEMETRY_ENABLED=false), so a bug report '
                .'would never be sent. Enable it, or send storage/logs/telemetry-*.log to support instead.'
            ),
            $this->file()
        );
        $this->assertSame([], $this->spooled());
    }

    public function test_no_monitoring_host_and_no_install_id_are_not_ready(): void
    {
        config(['monitoring.url' => '']);
        $this->assertSame(
            [
                'status' => 'not-ready',
                'queued' => false,
                'id' => null,
                'reason' => 'No monitoring host is configured (PANELALPHA_MONITORING is empty), '
                    .'so there is nowhere to send a bug report.',
                'endpoint' => '',
                'report' => null,
            ],
            $this->file()
        );

        config(['monitoring.url' => 'https://monitoring.test']);
        (new ReflectionProperty(Telemetry::class, 'installId'))->setValue(null, '');
        $this->assertSame(
            $this->refusal('not-ready', 'This install could not derive its install id, so a report would arrive unattributable.'),
            $this->file()
        );
        $this->assertSame([], $this->spooled());
    }

    public function test_a_preview_answers_with_the_report_and_writes_nothing(): void
    {
        $result = $this->file(['dry_run' => true]);

        $this->assertSame(['status', 'queued', 'id', 'reason', 'endpoint', 'report'], array_keys($result));
        $this->assertSame('preview', $result['status']);
        $this->assertFalse($result['queued']);
        $this->assertNull($result['reason']);
        $this->assertSame(self::ENDPOINT, $result['endpoint']);
        $this->assertSame($result['report']['id'], $result['id']);
        $this->assertSame(BugReport::KIND, $result['report']['kind']);
        $this->assertSame(str_repeat('b', 32), Telemetry::installId());
        $this->assertSame('high', $result['report']['bug']['severity']);
        $this->assertSame('deploy', $result['report']['bug']['area']);
        $this->assertSame('Deploys succeed but the site answers 502', $result['report']['bug']['title']);
        $this->assertArrayNotHasKey('log_tail', $result['report']);
        $this->assertSame([], $this->spooled());
    }

    public function test_a_queued_report_is_in_the_spool_under_its_own_id(): void
    {
        $result = $this->file();

        $this->assertSame(['status', 'queued', 'id', 'reason', 'endpoint', 'report'], array_keys($result));
        $this->assertSame('queued', $result['status']);
        $this->assertTrue($result['queued']);
        $this->assertNull($result['reason']);
        $this->assertSame(self::ENDPOINT, $result['endpoint']);
        $this->assertSame([$this->root.'/outbox/'.$result['id'].'.json'], $this->spooled());
        $this->assertSame($result['report'], Telemetry::spool()->pending(25, PHP_INT_MAX)[0]['report']);
    }

    public function test_a_spool_that_cannot_be_written_is_a_failure_naming_its_directory(): void
    {
        $this->useSpool('/proc/pa-no-such-spool/outbox');

        $result = $this->file();

        $this->assertSame(['status', 'queued', 'id', 'reason', 'endpoint', 'report'], array_keys($result));
        $this->assertSame('failed', $result['status']);
        $this->assertFalse($result['queued']);
        $this->assertSame($result['report']['id'], $result['id']);
        $this->assertSame(
            'The report could not be written to the telemetry spool at /proc/pa-no-such-spool/outbox'
            .' — check free disk space and permissions.',
            $result['reason']
        );
        $this->assertSame(self::ENDPOINT, $result['endpoint']);
    }
}

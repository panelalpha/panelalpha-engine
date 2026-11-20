<?php

namespace Tests\Integration\Console\Telemetry;

use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryDatabase;

/** Telemetry on with a pinned install id, spool and log in a temp dir, and the host probes answered up front. */
trait TelemetrySandbox
{
    use InMemoryDatabase;

    private string $telemetryRoot;

    protected function bootTelemetrySandbox(): void
    {
        $this->bootInMemoryDatabase();
        Setting::flushCache();
        $this->telemetryRoot = sys_get_temp_dir().'/pa-telemetry-cmd-'.bin2hex(random_bytes(6));
        mkdir($this->telemetryRoot.'/outbox', 0777, true);
        file_put_contents($this->telemetryRoot.'/install-id', str_repeat('a', 32));

        config([
            'telemetry.enabled' => true,
            'telemetry.bug_reports.enabled' => true,
            'telemetry.tier' => 2,
            'telemetry.spool_dir' => $this->telemetryRoot.'/outbox',
            'telemetry.pin_file' => $this->telemetryRoot.'/install-id',
            'telemetry.batch' => 25,
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
            'logging.channels.telemetry.path' => $this->telemetryRoot.'/telemetry.log',
            'system.version' => '9.9.9',
        ]);
        Telemetry::resetCache();
        $this->answerHostProbes(['OperatingSystem' => 'Ubuntu 24.04 LTS', 'ServerVersion' => '28.1.1', 'KernelVersion' => '6.8.0', 'Runtimes' => ['sysbox-runc' => [], 'runc' => []]]);
        Http::preventStrayRequests();
    }

    protected function tearDownTelemetrySandbox(): void
    {
        Telemetry::resetCache();
        Setting::flushCache();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->removeTree($this->telemetryRoot);
    }

    /** @param array<string, mixed> $dockerInfo */
    private function answerHostProbes(array $dockerInfo): void
    {
        (new \ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, $dockerInfo);
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');
    }

    /** @return list<string> */
    private function spooledReports(): array
    {
        return glob($this->telemetryRoot.'/outbox/*.json') ?: [];
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

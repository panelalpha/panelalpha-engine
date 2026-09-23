<?php

namespace App\Console\Commands\Telemetry;

use App\System;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\SourceBundlePolicy;
use App\Lib\Deploy\Telemetry\Telemetry;
use Illuminate\Console\Command;

class TelemetryStatusCommand extends Command
{
    protected $signature = 'telemetry:status';

    protected $description = 'Show deploy telemetry configuration, install id and queue state';

    public function handle(): int
    {
        $stats = Telemetry::spool()->stats();
        $installId = Telemetry::installId();

        $endpoint = Telemetry::endpoint();

        $this->table(['Setting', 'Value'], [
            // Named for what it actually controls. "Enabled: no" next to a
            // local log that is still filling up reads as a contradiction
            // otherwise, and that misreading is the whole point of the split.
            ['Sending reports', Telemetry::enabled() ? 'yes' : 'no'],
            // The switch a person hits before anything else, since filing a
            // report is refused rather than degraded when either is off.
            ['Bug reports', Telemetry::bugReportsEnabled() ? 'yes (telemetry:bug-report)' : 'no'],
            ['Notify email', NotificationPreferences::notifyEmail() ?? '(not set)'],
            ['Server probe URL', NotificationPreferences::serverProbeUrl() ?? '(unknown)'],
            ['App probe URLs', (string) count(NotificationPreferences::appProbeUrls())],
            ['Local log', $this->localLog()],
            ['Tier', $this->tierLabel(Telemetry::tier())],
            ['Source bundles', $this->bundleLabel(Telemetry::sourceBundleMode())],
            ['Endpoint', $endpoint === '' ? '(no monitoring host set — reports are held, not sent)' : $endpoint],
            ['Install ID', $installId === '' ? '(could not derive)' : $installId],
            ['Pin file', (string) config('telemetry.pin_file')],
            ['Spool', Telemetry::spool()->dir()],
            ['Queued', (string) $stats['count']],
            ['Queue size', $this->bytes($stats['bytes'])],
            ['Source bundles queued', $stats['bundles'] . ' (' . $this->bytes($stats['bundle_bytes']) . ')'],
            ['Oldest queued', $stats['oldest'] === null ? '-' : date('Y-m-d H:i:s', $stats['oldest'])],
        ]);

        if (!Telemetry::enabled()) {
            $this->newLine();
            $this->line('<comment>Reporting is off. Every deploy is still recorded to the local log '
                . 'above — that file can be shared with support at any time.</comment>');
        }

        $this->newLine();
        $this->line('<comment>Platform facts sent with each batch:</comment>');
        $rows = [];
        foreach (HostFacts::envelope(new System()) as $key => $value) {
            $rows[] = [$key, is_array($value) ? implode(', ', $value) : (string) ($value ?? '-')];
        }
        $this->table(['Fact', 'Value'], $rows);

        return 0;
    }

    private function bundleLabel(string $mode): string
    {
        return match ($mode) {
            SourceBundlePolicy::MODE_UNEXPLAINED => 'unexplained failures only (source code IS uploaded)',
            SourceBundlePolicy::MODE_FAILED => 'every failed deploy (source code IS uploaded)',
            default => 'off',
        };
    }

    /**
     * The always-on half: path plus how much of it is already there, so an
     * operator being asked for the file knows what they are looking for.
     */
    private function localLog(): string
    {
        $path = (string) config('logging.channels.telemetry.path', '');
        if ($path === '') {
            return '(channel not configured)';
        }

        $today = preg_replace('/\.log$/', '-' . date('Y-m-d') . '.log', $path) ?? $path;
        $existing = @glob(preg_replace('/\.log$/', '-*.log', $path) ?? '') ?: [];

        $bytes = 0;
        foreach ($existing as $file) {
            $bytes += (int) @filesize($file);
        }

        return $today . ' (' . count($existing) . ' file(s), ' . $this->bytes($bytes) . ')';
    }

    private function tierLabel(int $tier): string
    {
        return match ($tier) {
            0 => '0 (metadata only)',
            1 => '1 (+ repository identity)',
            default => '2 (+ redacted log tail)',
        };
    }

    private function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        return round($bytes / 1024 / 1024, 1) . ' MiB';
    }
}

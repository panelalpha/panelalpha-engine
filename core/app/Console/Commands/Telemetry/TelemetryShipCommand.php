<?php

namespace App\Console\Commands\Telemetry;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Deploy\Telemetry\TelemetryShipper;
use Illuminate\Console\Command;

class TelemetryShipCommand extends Command
{
    protected $signature = 'telemetry:ship
                            {--batch= : Reports to send in one request (default: telemetry.batch)}
                            {--dry-run : Show what would be sent without sending it}';

    protected $description = 'Send queued deploy telemetry reports to the ingest endpoint';

    public function handle(): int
    {
        if (! Telemetry::enabled()) {
            $this->info('Telemetry is disabled (TELEMETRY_ENABLED=false).');

            return 0;
        }

        $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;

        if ($this->option('dry-run')) {
            return $this->dryRun($batch);
        }

        $result = (new TelemetryShipper)->ship($batch);

        $line = sprintf(
            '%s — sent %d, rejected %d, kept %d, dropped %d (queue: %d)',
            $result['status'],
            $result['sent'],
            $result['rejected'],
            $result['kept'],
            $result['dropped'],
            $result['pending']
        );

        if ($result['bundles_sent'] > 0 || $result['bundles_failed'] > 0) {
            $line .= sprintf(
                ' | source bundles: %d uploaded, %d failed',
                $result['bundles_sent'],
                $result['bundles_failed']
            );
        }

        if ($result['message'] !== null) {
            $line .= ' | '.$result['message'];
        }

        // A failed send is a normal condition for an offline box, so it is not
        // an error exit: the scheduler would otherwise mail an operator about
        // their own firewall every five minutes.
        in_array($result['status'], ['ok', 'empty', 'disabled'], true)
            ? $this->info($line)
            : $this->warn($line);

        // Refresh probe targets (app FQDNs) so monitoring Up/Down stays current.
        if (in_array($result['status'], ['ok', 'empty'], true)) {
            $sync = NotificationPreferences::sync(true);
            if (! $sync['ok']) {
                $this->line('<comment>preferences sync: '.$sync['message'].'</comment>');
            }
        }

        return 0;
    }

    private function dryRun(?int $batch): int
    {
        $items = Telemetry::spool()->pending($batch ?? (int) config('telemetry.batch', 25));
        if ($items === []) {
            $this->info('Nothing queued.');

            return 0;
        }

        $this->info('Would POST '.count($items).' report(s) to '.Telemetry::endpoint());
        $this->table(
            ['ID', 'Outcome', 'Strategy', 'Rule', 'Source bundle', 'Attempts'],
            array_map(static function (array $item): array {
                $report = $item['report'];
                $bundle = $report['source_bundle'] ?? null;

                return [
                    $item['id'],
                    $report['outcome'] ?? '-',
                    $report['deploy']['strategy'] ?? '-',
                    $report['failure']['rule'] ?? '(unexplained)',
                    $item['bundle'] !== null
                        ? round((int) ($bundle['bytes'] ?? filesize($item['bundle'])) / 1024).' KiB, held'
                        : '-',
                    $item['attempts'],
                ];
            }, $items)
        );

        $this->line('');
        $this->line('<comment>Bundles are uploaded only for reports the server asks for by id.</comment>');

        return 0;
    }
}

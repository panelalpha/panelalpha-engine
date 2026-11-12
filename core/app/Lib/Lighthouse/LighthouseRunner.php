<?php

namespace App\Lib\Lighthouse;

use App\System;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;

/** Runs Lighthouse in its compose service and reads back the report it writes. */
final class LighthouseRunner
{
    public function __construct(private readonly System $system) {}

    /**
     * The report path is the container's /data, as the engine sees it. The broker names it after the URL and form
     * factor, so two runs of the same pair take turns rather than share one file.
     *
     * @return array<array-key, mixed>
     *
     * @throws LighthouseFailed with the message the API answers with
     */
    public function report(string $url, bool $desktop, string $resolverRules): array
    {
        $name = md5($url).($desktop ? '-desktop' : '-mobile');
        $directory = $this->system->engineDirPath().'/data/lighthouse';

        return $this->oneAtATime("{$directory}/{$name}.lock", function () use ($url, $desktop, $resolverRules, $directory, $name): array {
            $this->run(Privileged::engineServices($this->system->composeFilePath())->lighthouseAudit($url, $desktop, $resolverRules));

            return $this->readReport("{$directory}/{$name}.json");
        });
    }

    /**
     * Nowhere to put the lock is not a reason to refuse the audit.
     *
     * @param  callable(): array<array-key, mixed>  $audit
     * @return array<array-key, mixed>
     */
    private function oneAtATime(string $lockPath, callable $audit): array
    {
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            Log::warning("Lighthouse report lock {$lockPath} unavailable; auditing without it.");

            return $audit();
        }
        try {
            flock($lock, LOCK_EX);

            return $audit();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param  list<string>  $args */
    private function run(array $args): void
    {
        try {
            $this->system->exec($args);
        } catch (\Exception $e) {
            throw new LighthouseFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * The directory is the engine's, so removing the root-written report needs no root.
     *
     * @return array<array-key, mixed>
     *
     * @throws LighthouseFailed
     */
    private function readReport(string $reportPath): array
    {
        if (! file_exists($reportPath)) {
            throw new LighthouseFailed('report file not found');
        }
        $json = file_get_contents($reportPath);
        @unlink($reportPath);
        if (! is_string($json)) {
            throw new LighthouseFailed('cannot read report file');
        }

        $report = json_decode($json, true);
        if (! is_array($report)) {
            throw new LighthouseFailed('cannot parse report file');
        }

        return $report;
    }
}

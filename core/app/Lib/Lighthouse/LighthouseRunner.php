<?php

namespace App\Lib\Lighthouse;

use App\System;

/** Runs Lighthouse in its compose service and reads back the report it writes. */
final class LighthouseRunner
{
    public function __construct(private readonly System $system)
    {
    }

    /**
     * @return array<array-key, mixed>
     * @throws LighthouseFailed with the message the API answers with
     */
    public function report(string $url, bool $desktop, string $resolverRules): array
    {
        $filename = md5($url) . ($desktop ? '-desktop' : '-mobile') . '.json';
        // The container's /data, as the engine sees it.
        $reportPath = $this->system->engineDirPath() . "/data/lighthouse/{$filename}";

        $chromeFlags = [
            '--headless',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--ignore-certificate-errors',
            '--host-resolver-rules="' . $resolverRules . '"',
        ];

        $args = [
            "sudo",
            "docker",
            "compose",
            "-f",
            $this->system->composeFilePath(),
            "exec",
            "-T",
            "lighthouse",
            "lighthouse",
            $url,
            "--output",
            "json",
            "--output-path",
            "/data/{$filename}",
            "--chrome-flags=" . escapeshellarg(implode(" ", $chromeFlags)),
            "--ignore-status-code",
            "--no-enable-error-reporting",
            "--only-audits=final-screenshot",
            "--only-categories=performance",
        ];
        if ($desktop) {
            $args[] = "--preset=desktop";
        }
        try {
            $this->system->exec($args);
        } catch (\Exception $e) {
            throw new LighthouseFailed($e->getMessage(), 0, $e);
        }

        if (!file_exists($reportPath)) {
            throw new LighthouseFailed('report file not found');
        }
        $json = file_get_contents($reportPath);
        $this->system->exec(["sudo", "rm", "-rf", $reportPath]);
        if (!is_string($json)) {
            throw new LighthouseFailed('cannot read report file');
        }

        $report = json_decode($json, true);
        if (!is_array($report)) {
            throw new LighthouseFailed('cannot parse report file');
        }

        return $report;
    }
}

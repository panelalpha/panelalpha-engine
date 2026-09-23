<?php

namespace App\System\Project;

use App\Models\Domain;
use App\System\Project as UserProject;
use App\System\Project\PhpHosting\PhpHandlerNotRunning;
use Illuminate\Validation\ValidationException;

class Php
{
    public function __construct(
        private readonly UserProject $project,
    ) {
    }

    public function customIniFilePath(string $phpVersion): string
    {
        return $this->project->projectDirPath() . "/php/{$phpVersion}/custom.ini";
    }

    /**
     * @return array<string, string>
     */
    public function getCustomIniSettings(string $phpVersion): array
    {
        $iniFile = $this->customIniFilePath($phpVersion);

        return parse_ini_file($iniFile);
    }

    /**
     * @param array<string, string> $settings
     *
     * @throws \InvalidArgumentException
     */
    public function updateCustomIniSettings(string $phpVersion, array $settings): void
    {
        $iniFile = $this->customIniFilePath($phpVersion);

        $iniString = $this->encodeIniMap($settings);

        file_put_contents($iniFile, $iniString);
        $this->restartPhpHandler($phpVersion);
    }

    /**
     * Domain PHP directives: the per-document-root file PHP reads for web requests.
     * Two domains that share a document root share one set.
     *
     * @return array<string, string>
     */
    public function getDomainDirectives(Domain $domain): array
    {
        $path = $this->domainDirectivesPath($domain);
        $system = $this->project->system();
        if (!$system->fileExists($path)) {
            return [];
        }

        try {
            return $this->parseIniMap($system->fileGetContents($path));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'settings' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, string> $settings
     */
    public function replaceDomainDirectives(Domain $domain, array $settings): void
    {
        $path = $this->domainDirectivesPath($domain);
        $system = $this->project->system();
        $directory = dirname($path);
        if (!$system->directoryExists($directory)) {
            throw ValidationException::withMessages([
                'document_root' => 'Document root does not exist.',
            ]);
        }

        if ($settings === []) {
            if ($system->fileExists($path)) {
                $system->exec(['sudo', 'rm', '-f', $path]);
            }

            return;
        }

        try {
            $contents = $this->encodeIniMap($settings);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'settings' => $e->getMessage(),
            ]);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'pa-ini-');
        if ($temporary === false || file_put_contents($temporary, $contents) === false) {
            throw ValidationException::withMessages([
                'settings' => 'Could not write domain PHP directives.',
            ]);
        }

        try {
            $system->exec(['sudo', 'cp', $temporary, $path]);
            $chown = $this->project->model()->getChownString();
            if ($chown !== null) {
                $system->exec(['sudo', 'chown', $chown, $path]);
            }
            $system->exec(['sudo', 'chmod', '644', $path]);
        } finally {
            @unlink($temporary);
        }
    }

    private function domainDirectivesPath(Domain $domain): string
    {
        $relative = rtrim($domain->getDocumentRoot(), '/') . '/.user.ini';

        return $this->project->fileManager()->resolvePath($relative);
    }

    /**
     * @param array<string, string> $settings
     */
    private function encodeIniMap(array $settings): string
    {
        $lines = '';
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '' || strpbrk($key, "\r\n=") !== false || !is_string($value)) {
                throw new \InvalidArgumentException('Invalid directive name.');
            }
            $lines .= $key . '=' . $value . "\n";
        }

        if ($this->parseIniMap($lines) !== $settings) {
            throw new \InvalidArgumentException('Invalid INI.');
        }

        return $lines;
    }

    /**
     * Flat directives only. Sections are not part of the map.
     *
     * @return array<string, string>
     */
    private function parseIniMap(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        set_error_handler(static fn (): bool => true);
        try {
            $parsed = parse_ini_string($contents, true, INI_SCANNER_NORMAL);
        } finally {
            restore_error_handler();
        }

        if (!is_array($parsed)) {
            throw new \InvalidArgumentException('Directive file is not valid INI.');
        }

        $flat = [];
        foreach ($parsed as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $flat[(string) $key] = (string) $value;
        }

        return $flat;
    }

    private function restartPhpHandler(string $phpVersion): void
    {
        $runtime = $this->project->runtime();
        if (!$runtime instanceof PhpHosting) {
            return;
        }
        try {
            $runtime->phpRuntime()->restartPhpHandler($phpVersion);
        } catch (PhpHandlerNotRunning) {
            // No domain uses this version: the file is saved and takes effect
            // when one does. Said in the log so it is not a silent success.
            \Illuminate\Support\Facades\Log::info(
                "PHP {$phpVersion} settings saved for {$this->project->username()}; no domain runs that version yet"
            );
        }
    }
}

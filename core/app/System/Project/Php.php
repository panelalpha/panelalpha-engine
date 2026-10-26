<?php

namespace App\System\Project;

use App\Lib\Project\CustomIniSettings;
use App\Models\Domain;
use App\System\Project as UserProject;
use App\System\Project\PhpHosting\PhpErrorLogConfig;
use App\System\Project\PhpHosting\PhpHandlerNotRunning;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Php
{
    public function __construct(
        private readonly UserProject $project,
    ) {}

    public function customIniFilePath(string $phpVersion): string
    {
        return $this->project->projectDirPath()."/php/{$phpVersion}/custom.ini";
    }

    /**
     * Empty for a version the project template did not ship, which has no file yet.
     *
     * @return array<string, string>
     */
    public function getCustomIniSettings(string $phpVersion): array
    {
        $iniFile = $this->customIniFilePath($phpVersion);
        if (! is_file($iniFile)) {
            return [];
        }

        $parsed = @parse_ini_file($iniFile);
        if ($parsed === false) {
            throw ValidationException::withMessages([
                'settings' => 'The custom PHP INI file is not valid INI.',
            ]);
        }

        return $parsed;
    }

    /**
     * @param  array<string, string>  $settings
     *
     * @throws \InvalidArgumentException
     */
    public function updateCustomIniSettings(string $phpVersion, array $settings): void
    {
        $this->applyPhpErrorLogConfig();
        file_put_contents($this->customIniFilePath($phpVersion), $this->encodeIniMap($settings));
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
        $this->requirePhpHosting();
        $path = $this->domainDirectivesPath($domain);
        $system = $this->project->system();
        if (! $system->fileExists($path)) {
            return [];
        }

        try {
            return $this->parseIniMap($system->fileGetContents($path));
        } catch (\InvalidArgumentException $e) {
            throw self::invalidSettings($e->getMessage());
        }
    }

    /**
     * An empty map removes the file.
     *
     * @param  array<string, string>  $settings
     */
    public function replaceDomainDirectives(Domain $domain, array $settings): void
    {
        $this->requirePhpHosting();
        $path = $this->domainDirectivesPath($domain);
        $system = $this->project->system();
        if (! $system->directoryExists(dirname($path))) {
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
            throw self::invalidSettings($e->getMessage());
        }
        $this->installDirectivesFile($contents, $path);
    }

    /** Staged in a temp file the engine can write, then copied in with sudo and owned by the account. */
    private function installDirectivesFile(string $contents, string $path): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'pa-ini-');
        if ($temporary === false || file_put_contents($temporary, $contents) === false) {
            throw self::invalidSettings('Could not write domain PHP directives.');
        }

        $system = $this->project->system();
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

    private static function invalidSettings(string $message): ValidationException
    {
        return ValidationException::withMessages([
            'settings' => $message,
        ]);
    }

    private function domainDirectivesPath(Domain $domain): string
    {
        $relative = rtrim($domain->getDocumentRoot(), '/').'/.user.ini';

        return $this->project->fileManager()->resolvePath($relative);
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function encodeIniMap(array $settings): string
    {
        $lines = '';
        foreach ($settings as $key => $value) {
            if (! is_string($key) || $key === '' || strpbrk($key, "\r\n=") !== false || ! is_string($value)) {
                throw new \InvalidArgumentException('Invalid directive name.');
            }
            $lines .= $key.'='.$value."\n";
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

        if (! is_array($parsed)) {
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

    /**
     * A dind app runs PHP from its own image and reads none of these files, so
     * a write would succeed and change nothing ({@see CustomIniSettings}).
     */
    private function requirePhpHosting(): void
    {
        if (! $this->project->runtime() instanceof PhpHosting) {
            throw ValidationException::withMessages([
                'project' => 'Custom PHP INI settings apply only to PHP hosting projects. '
                    .'A dind project runs PHP from its own image; set php.ini there.',
            ]);
        }
    }

    /**
     * A settings change re-renders the error-log directives too, so a project
     * that predates them picks them up without a separate command.
     */
    private function applyPhpErrorLogConfig(): void
    {
        $runtime = $this->project->runtime();
        if ($runtime instanceof PhpHosting) {
            (new PhpErrorLogConfig)->apply($runtime);
        }
    }

    /**
     * With no domain on this version yet the file is saved and takes effect when one
     * uses it; that is logged so it is not a silent success.
     */
    private function restartPhpHandler(string $phpVersion): void
    {
        $runtime = $this->project->runtime();
        if (! $runtime instanceof PhpHosting) {
            return;
        }
        try {
            $runtime->phpRuntime()->restartPhpHandler($phpVersion);
        } catch (PhpHandlerNotRunning) {
            Log::info(
                "PHP {$phpVersion} settings saved for {$this->project->username()}; no domain runs that version yet"
            );
        }
    }
}

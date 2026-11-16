<?php

namespace App\System\Project;

use App\Lib\Project\CustomIniSettings;
use App\Models\Domain;
use App\System;
use App\System\Privileged;
use App\System\Privileged\AccountFiles;
use App\System\Project as UserProject;
use App\System\Project\PhpHosting\PhpErrorLogConfig;
use App\System\Project\PhpHosting\PhpHandlerNotRunning;
use App\System\Project\PhpHosting\PhpRuntime;
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

        $contents = @file_get_contents($iniFile);
        $directives = is_string($contents) ? self::directivesAsWritten($contents) : null;
        if ($directives === null) {
            throw self::invalidSettings('The custom PHP INI file is not valid INI.');
        }

        return $directives;
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
            $this->removeDirectivesFile($system, $path);
        } else {
            $this->installDirectivesFile($this->encodedDirectives($settings), $path);
        }

        $this->reloadDomainPhpHandler($domain);
    }

    /**
     * php-fpm reads the file again within user_ini.cache_ttl, which the pool and the version's default.ini set to 3 s;
     * a handler that started before the pool carried it replaces its workers as before. lsphp restarts.
     */
    private function reloadDomainPhpHandler(Domain $domain): void
    {
        $phpVersion = $domain->getPhpVersion();
        if ($phpVersion !== null) {
            $this->reloadPhpHandler($phpVersion);
        }
    }

    private function removeDirectivesFile(System $system, string $path): void
    {
        if (! $system->fileExists($path)) {
            return;
        }
        $system->exec($this->isInTheHomeItself($path)
            ? Privileged::files()->removeIfExists($path)
            : $this->accountFiles()->removeIfExists($path));
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function encodedDirectives(array $settings): string
    {
        try {
            return $this->encodeIniMap($settings);
        } catch (\InvalidArgumentException $e) {
            throw self::invalidSettings($e->getMessage());
        }
    }

    /** Written as the account, so a planted link reaches only the account's own files. */
    private function installDirectivesFile(string $contents, string $path): void
    {
        if ($this->isInTheHomeItself($path)) {
            $this->project->system()->filesystem()->writeFileReplacingPath($path, $contents, $this->accountOwner(), '644');

            return;
        }
        $accountFiles = $this->accountFiles();
        $temporary = tempnam(sys_get_temp_dir(), 'pa-ini-');
        if ($temporary === false || file_put_contents($temporary, $contents) === false) {
            throw self::invalidSettings('Could not write domain PHP directives.');
        }

        try {
            $this->project->system()->exec($accountFiles->writeFrom($temporary, $path, '644'));
        } finally {
            @unlink($temporary);
        }
    }

    /** The home is root's, so only root can write a document root there. */
    private function isInTheHomeItself(string $path): bool
    {
        return dirname($path) === rtrim($this->project->homeDirPath(), '/');
    }

    private function accountFiles(): AccountFiles
    {
        return Privileged::accountFiles(...$this->project->model()->linuxIds());
    }

    private function accountOwner(): string
    {
        return implode(':', $this->project->model()->linuxIds());
    }

    private static function invalidSettings(string $message): ValidationException
    {
        return ValidationException::withMessages([
            'settings' => $message,
        ]);
    }

    private function domainDirectivesPath(Domain $domain): string
    {
        return $this->project->fileManager()->resolvePath(self::domainDirectiveRelativePath($domain));
    }

    /**
     * The Engine's own record of a domain's directives, a shared document root once.
     *
     * @return list<string>
     */
    public function domainDirectiveRelativePaths(): array
    {
        $paths = [];
        foreach ($this->project->model()->domains as $domain) {
            $paths[self::domainDirectiveRelativePath($domain)] = true;
        }

        return array_keys($paths);
    }

    private static function domainDirectiveRelativePath(Domain $domain): string
    {
        return rtrim($domain->getDocumentRoot(), '/').'/.user.ini';
    }

    /**
     * Each value is written as given, so a line is refused when PHP would not read it back the same.
     *
     * @param  array<string, string>  $settings
     */
    private function encodeIniMap(array $settings): string
    {
        $lines = '';
        foreach ($settings as $key => $value) {
            if (! is_string($key) || $key === '' || strpbrk($key, "\r\n=") !== false || ! is_string($value)) {
                throw new \InvalidArgumentException('Invalid directive name.');
            }
            $line = $key.'='.$value;
            if (self::directivesAsWritten($line) !== [$key => $value]) {
                throw new \InvalidArgumentException("Invalid INI: PHP would not read \"{$line}\" as written.");
            }
            $lines .= $line."\n";
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

        $directives = self::directivesAsWritten($contents);
        if ($directives === null) {
            throw new \InvalidArgumentException('Directive file is not valid INI.');
        }

        return $directives;
    }

    /**
     * The values as written, since PHP reads `Off` as "" and `E_ALL` as a number; null for text PHP itself cannot read,
     * which would stop it reading the whole file.
     *
     * @return array<string, string>|null
     */
    private static function directivesAsWritten(string $contents): ?array
    {
        $written = self::parsedIni($contents, INI_SCANNER_RAW);
        if ($written === false || self::parsedIni($contents, INI_SCANNER_NORMAL) === false) {
            return null;
        }

        return self::flatDirectives($written);
    }

    /** @return array<mixed>|false */
    private static function parsedIni(string $contents, int $scannerMode): array|false
    {
        set_error_handler(static fn (): bool => true);
        try {
            return parse_ini_string($contents, true, $scannerMode);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  array<mixed>  $parsed
     * @return array<string, string>
     */
    private static function flatDirectives(array $parsed): array
    {
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
     * With no handler running that version the file is saved and takes effect when one
     * uses it; that is logged so it is not a silent success.
     */
    private function reloadPhpHandler(string $phpVersion): void
    {
        $this->savedWithoutHandler(
            $phpVersion,
            fn (PhpRuntime $phpRuntime) => $phpRuntime->reloadPhpHandler($phpVersion)
        );
    }

    /** A php.ini value is read when the handler starts, so that one takes a restart. */
    private function restartPhpHandler(string $phpVersion): void
    {
        $this->savedWithoutHandler(
            $phpVersion,
            fn (PhpRuntime $phpRuntime) => $phpRuntime->restartPhpHandler($phpVersion)
        );
    }

    /** @param  callable(PhpRuntime): void  $apply */
    private function savedWithoutHandler(string $phpVersion, callable $apply): void
    {
        $runtime = $this->project->runtime();
        if (! $runtime instanceof PhpHosting) {
            return;
        }
        try {
            $apply($runtime->phpRuntime());
        } catch (PhpHandlerNotRunning) {
            Log::info(
                "PHP {$phpVersion} settings saved for {$this->project->username()}; no PHP handler of that version is running"
            );
        }
    }
}

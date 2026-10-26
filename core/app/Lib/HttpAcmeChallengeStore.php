<?php

namespace App\Lib;

use App\Models\Domain;
use App\System;
use InvalidArgumentException;

class HttpAcmeChallengeStore
{
    /** ACME token = base64url alphabet, RFC 8555 §8.3 */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Key authorization body = token + '.' + base64url(SHA-256 JWK thumbprint).
     * Thumbprint is always 43 chars (RFC 8555 §8.1); token capped at 255 in the API.
     */
    public const MAX_CONTENT_LENGTH = 512;

    public const DEFAULT_TTL_HOURS = 24;

    protected System $system;

    public function __construct(?System $system = null)
    {
        $this->system = $system ?? new System;
    }

    public function basePath(): string
    {
        return $this->system->engineDirPath().'/data/webserver/acme-challenges';
    }

    public function domainDir(string $primaryDomain): string
    {
        return $this->basePath().'/'.$primaryDomain;
    }

    public function challengePath(string $primaryDomain, string $token): string
    {
        $this->assertValidToken($token);

        return $this->domainDir($primaryDomain).'/'.$token;
    }

    /**
     * @return array{http_acme_challenges_enabled: bool, http_acme_challenges_dir: string}
     */
    public function templateVars(string $primaryDomain): array
    {
        $dir = $this->domainDir($primaryDomain);

        return [
            'http_acme_challenges_enabled' => $this->enabled($primaryDomain),
            'http_acme_challenges_dir' => $dir,
        ];
    }

    public function enabled(string $primaryDomain): bool
    {
        return count($this->listTokens($primaryDomain)) > 0;
    }

    /**
     * @return list<string>
     */
    public function listTokens(string $primaryDomain): array
    {
        $dir = $this->domainDir($primaryDomain);
        if (! $this->system->directoryExists($dir)) {
            return [];
        }

        $process = $this->system->runProcess(['sudo', 'ls', '-1A', $dir]);
        if ($process->getExitCode() !== 0) {
            return [];
        }

        $tokens = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $name) {
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            if (preg_match(self::TOKEN_PATTERN, $name)) {
                $tokens[] = $name;
            }
        }

        sort($tokens);

        return $tokens;
    }

    /**
     * @return list<array{token: string, content: string}>
     */
    public function list(string $primaryDomain): array
    {
        $result = [];
        foreach ($this->listTokens($primaryDomain) as $token) {
            $content = $this->get($primaryDomain, $token);
            if ($content === null) {
                continue;
            }
            $result[] = [
                'token' => $token,
                'content' => $content,
            ];
        }

        return $result;
    }

    public function get(string $primaryDomain, string $token): ?string
    {
        $this->assertValidToken($token);
        $path = $this->challengePath($primaryDomain, $token);
        $fs = $this->system->filesystem();
        if (! $fs->fileExists($path)) {
            return null;
        }

        return $fs->fileGetContents($path);
    }

    public function exists(string $primaryDomain, string $token): bool
    {
        $this->assertValidToken($token);

        return $this->system->filesystem()->fileExists($this->challengePath($primaryDomain, $token));
    }

    /**
     * @return array{became_enabled: bool}
     */
    public function put(string $primaryDomain, string $token, string $content): array
    {
        $this->assertValidToken($token);
        $this->assertValidContent($content);

        if ($this->exists($primaryDomain, $token)) {
            throw new InvalidArgumentException('Challenge token already exists');
        }

        $wasEnabled = $this->enabled($primaryDomain);
        $dir = $this->domainDir($primaryDomain);
        $fs = $this->system->filesystem();
        $fs->makeDirWithParents($dir);
        $fs->filePutContents($this->challengePath($primaryDomain, $token), $content, null, '644');

        return ['became_enabled' => ! $wasEnabled];
    }

    /**
     * @return array{became_disabled: bool, deleted: bool}
     */
    public function delete(string $primaryDomain, string $token): array
    {
        $this->assertValidToken($token);
        $path = $this->challengePath($primaryDomain, $token);
        if (! $this->system->filesystem()->fileExists($path)) {
            return ['became_disabled' => false, 'deleted' => false];
        }

        $wasEnabled = $this->enabled($primaryDomain);
        $this->system->exec(['sudo', 'rm', '-f', $path]);
        $this->removeDomainDirIfEmpty($primaryDomain);

        return [
            'became_disabled' => $wasEnabled && ! $this->enabled($primaryDomain),
            'deleted' => true,
        ];
    }

    /**
     * @return array{became_disabled: bool, deleted_count: int}
     */
    public function deleteAll(string $primaryDomain): array
    {
        $tokens = $this->listTokens($primaryDomain);
        if ($tokens === []) {
            return ['became_disabled' => false, 'deleted_count' => 0];
        }

        $dir = $this->domainDir($primaryDomain);
        $this->system->exec(['sudo', 'rm', '-rf', $dir]);

        return [
            'became_disabled' => true,
            'deleted_count' => count($tokens),
        ];
    }

    public function deleteDomainDir(string $primaryDomain): void
    {
        $dir = $this->domainDir($primaryDomain);
        if ($this->system->directoryExists($dir)) {
            $this->system->exec(['sudo', 'rm', '-rf', $dir]);
        }
    }

    /**
     * Remove challenge files older than TTL. Returns primary domain names whose
     * ACME vhost block should be disabled (dir became empty).
     *
     * @return list<string>
     */
    public function prune(int $ttlHours = self::DEFAULT_TTL_HOURS): array
    {
        $base = $this->basePath();
        if (! $this->system->directoryExists($base)) {
            return [];
        }

        $mmin = max(1, $ttlHours * 60);
        $this->system->runProcess([
            'sudo',
            'find',
            $base,
            '-mindepth',
            '2',
            '-maxdepth',
            '2',
            '-type',
            'f',
            '-mmin',
            '+'.$mmin,
            '-delete',
        ]);

        $domainsNeedingRebuild = [];
        $process = $this->system->runProcess(['sudo', 'ls', '-1A', $base]);
        if ($process->getExitCode() !== 0) {
            return [];
        }

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $domainName) {
            if ($domainName === '' || $domainName === '.' || $domainName === '..') {
                continue;
            }

            $dir = $this->domainDir($domainName);
            if (! $this->system->directoryExists($dir)) {
                continue;
            }

            if ($this->listTokens($domainName) === []) {
                $this->system->exec(['sudo', 'rm', '-rf', $dir]);
                if (Domain::findByName($domainName)) {
                    $domainsNeedingRebuild[] = $domainName;
                }
            }
        }

        return $domainsNeedingRebuild;
    }

    public function assertValidToken(string $token): void
    {
        if ($token === '' || ! preg_match(self::TOKEN_PATTERN, $token)) {
            throw new InvalidArgumentException('Invalid ACME challenge token');
        }
    }

    public function assertValidContent(string $content): void
    {
        if ($content === '') {
            throw new InvalidArgumentException('Challenge content must not be empty');
        }
        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new InvalidArgumentException('Challenge content exceeds maximum length');
        }
    }

    private function removeDomainDirIfEmpty(string $primaryDomain): void
    {
        if ($this->listTokens($primaryDomain) !== []) {
            return;
        }

        $dir = $this->domainDir($primaryDomain);
        if ($this->system->directoryExists($dir)) {
            $this->system->exec(['sudo', 'rm', '-rf', $dir]);
        }
    }
}

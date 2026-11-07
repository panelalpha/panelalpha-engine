<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DockerfileFinder;

/**
 * When a source bundle (the customer's source, off unless an operator turns it on) may be made, {@see shouldCapture()},
 * and which files may travel, {@see shouldInclude()}. An ambiguous rule excludes: a shipped private key is an incident.
 */
class SourceBundlePolicy
{
    /** Never capture. The default. */
    public const MODE_OFF = 'off';

    /** Only failures {@see DeployFailureExplainer} could not name. */
    public const MODE_UNEXPLAINED = 'unexplained';

    /** Every failed deploy. */
    public const MODE_FAILED = 'failed';

    public const MODES = [self::MODE_OFF, self::MODE_UNEXPLAINED, self::MODE_FAILED];

    /**
     * Build output, dependency trees and VCS history, dropped wherever they appear. `docker` is absent on purpose:
     * `docker/Dockerfile` is one of {@see DockerfileFinder::NESTED_CANDIDATES}, what a failed Dockerfile build needs.
     *
     * @var list<string>
     */
    public const EXCLUDED_DIRECTORIES = [
        '.git', '.svn', '.hg', '.bzr',
        'node_modules', 'bower_components', 'vendor',
        '.next', '.nuxt', '.svelte-kit', '.astro', '.output', '.vercel', '.netlify',
        'dist', 'build', 'out', 'target', '_build',
        '.venv', 'venv', 'env', '__pycache__', '.tox', '.mypy_cache', '.pytest_cache', '.ruff_cache',
        '.gradle', '.mvn',
        '.cache', '.turbo', '.parcel-cache', '.yarn', '.pnpm-store', '.bundle',
        'coverage', '.nyc_output',
        '.terraform', '.serverless',
        '.ssh', '.aws', '.gnupg', '.config',
        '.idea', '.vscode', '.fleet',
        '.DS_Store',
    ];

    /**
     * Files whose whole point is to hold a secret or a copy of customer data.
     *
     * @var list<string>
     */
    private const SENSITIVE_BASENAMES = [
        'id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519',
        '.npmrc', '.pypirc', '.netrc', '_netrc', '.git-credentials', '.htpasswd',
        'credentials', 'credentials.json', 'secrets.json', 'secrets.yml', 'secrets.yaml',
        '.master.key', 'master.key', '.rgignore',
    ];

    /**
     * @var list<string>
     */
    private const SENSITIVE_EXTENSIONS = [
        'pem', 'key', 'crt', 'cer', 'der', 'p12', 'pfx', 'jks', 'keystore',
        'asc', 'gpg', 'pgp', 'ppk',
        'sqlite', 'sqlite3', 'db', 'sql', 'dump', 'bak',
        'kubeconfig',
    ];

    /**
     * `.env` variants that carry no values and are what the engine's env materialisation reads, so a deploy
     * that failed on a missing variable is unreadable without them.
     *
     * @var list<string>
     */
    private const ALLOWED_ENV_FILES = [
        '.env.example', '.env.sample', '.env.dist', '.env.template', '.env.defaults',
    ];

    public static function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF;
    }

    /**
     * Is this outcome a candidate for a bundle? Only an outright failure: a `partial` deploy started, and a
     * `recovered` signal is one event in an otherwise fine deploy.
     */
    public static function shouldCapture(string $mode, string $outcome, ?string $rule): bool
    {
        if (self::normalizeMode($mode) === self::MODE_OFF) {
            return false;
        }
        if ($outcome !== DeployReport::OUTCOME_FAILED) {
            return false;
        }
        if (self::normalizeMode($mode) === self::MODE_UNEXPLAINED) {
            return $rule === null;
        }

        return true;
    }

    /**
     * May this file travel?
     *
     * @param  string  $relativePath  forward-slashed, relative to the project root
     */
    public static function shouldInclude(string $relativePath, int $sizeBytes, int $maxFileBytes): bool
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return false;
        }

        if ($sizeBytes > $maxFileBytes) {
            return false;
        }

        $segments = explode('/', $relativePath);
        $basename = array_pop($segments);

        foreach ($segments as $segment) {
            if (self::isExcludedDirectory($segment)) {
                return false;
            }
        }

        return ! self::isSensitiveBasename($basename);
    }

    public static function isExcludedDirectory(string $segment): bool
    {
        return in_array($segment, self::EXCLUDED_DIRECTORIES, true);
    }

    public static function isSensitiveBasename(string $basename): bool
    {
        $lower = strtolower($basename);

        if (in_array($lower, self::ALLOWED_ENV_FILES, true)) {
            return false;
        }

        if (self::isEnvFile($lower)) {
            return true;
        }

        if (in_array($lower, self::SENSITIVE_BASENAMES, true)) {
            return true;
        }

        if (self::isKeyPairFile($lower)) {
            return true;
        }

        if (str_contains($lower, 'service-account') && str_ends_with($lower, '.json')) {
            return true;
        }

        $extension = strtolower((string) pathinfo($lower, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::SENSITIVE_EXTENSIONS, true);
    }

    /** .env, .env.local, .env.production: any not on the allow-list, which is asked first. */
    private static function isEnvFile(string $lower): bool
    {
        return $lower === '.env' || str_starts_with($lower, '.env.');
    }

    /** id_rsa.pub, id_ed25519-work and the like. */
    private static function isKeyPairFile(string $lower): bool
    {
        foreach (['id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519'] as $keyName) {
            if (str_starts_with($lower, $keyName)) {
                return true;
            }
        }

        return false;
    }
}

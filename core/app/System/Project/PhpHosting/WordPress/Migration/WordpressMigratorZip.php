<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Lib\Http\BoundedDownload;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Downloads the pinned migrator release once per migration run, refuses it
 * unless its SHA-256 is the pinned one, and repacks it so WordPress installs
 * the plugin under wordpress-migrator/, not the GitHub tag folder. The run
 * removes it when it ends ({@see discard}).
 */
class WordpressMigratorZip
{
    /** The first release whose transfer routes refuse a call without the site's token. */
    public const AUTHENTICATED_VERSION = '1.2.0';

    private const PLUGIN_DIR = 'wordpress-migrator';

    private const REQUIRED_ENTRY = 'wordpress-migrator.php';

    private const EXCLUDED = ['branding.php'];

    /** A release zip is a few hundred kilobytes; a download past this is not one. */
    private const MAX_RELEASE_BYTES = 20 * 1024 * 1024;

    /** Inside an HTTP request a stalled download must not hold a PHP-FPM worker long. */
    public const REQUEST_DOWNLOAD_SECONDS = 120;

    /** A queued import job may wait as long as any bounded download. */
    public const JOB_DOWNLOAD_SECONDS = 600;

    /** @var array<int, string> migration id => absolute path to install zip */
    private static array $cache = [];

    public function __construct(
        private readonly int $downloadSeconds = self::REQUEST_DOWNLOAD_SECONDS,
    ) {}

    /**
     * Install zip path for this migration, downloading and repacking at most
     * once per run for a given record id, and again when the file is gone.
     */
    public function installZipFor(int $migrationId, ?string $releaseUrl = null): string
    {
        if (isset(self::$cache[$migrationId]) && is_file(self::$cache[$migrationId])) {
            return self::$cache[$migrationId];
        }

        self::discard($migrationId);
        $path = $this->downloadAndRepack($releaseUrl);
        self::$cache[$migrationId] = $path;

        return $path;
    }

    /**
     * Delete the temp work directory for a migration once the plugin installs
     * have read the zip, and when its run ends. Ignores paths outside the
     * migrator temp prefix.
     */
    public static function discard(int $migrationId): void
    {
        $path = self::$cache[$migrationId] ?? null;
        unset(self::$cache[$migrationId]);
        if (! is_string($path) || $path === '') {
            return;
        }

        $workDir = dirname($path);
        $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'wp-migrator-';
        if (! str_starts_with($workDir, $prefix)) {
            return;
        }

        self::removeDir($workDir);
    }

    /**
     * The release zip for a user to install by hand, with this token in its
     * config.php. Returns a standalone temp file the caller deletes.
     */
    public function packageWithToken(string $token, ?string $releaseUrl = null): string
    {
        $installZip = $this->downloadAndRepack($releaseUrl);
        $workDir = dirname($installZip);
        try {
            self::writeToken($installZip, $token);
            $package = tempnam(sys_get_temp_dir(), 'wp-migrator-package-');
            if ($package === false || ! copy($installZip, $package)) {
                throw new RuntimeException('Could not write the migrator plugin package');
            }

            return $package;
        } finally {
            self::removeDir($workDir);
        }
    }

    /**
     * Put the token into the install zip's config.php, the way the plugin's
     * own "generate token" button writes it.
     */
    public static function writeToken(string $installZip, string $token): void
    {
        $entry = self::PLUGIN_DIR.'/config.php';
        $zip = new ZipArchive;
        if ($zip->open($installZip) !== true) {
            throw new RuntimeException('Could not open migrator install zip');
        }

        try {
            $config = $zip->getFromName($entry);
            $count = 0;
            $updated = is_string($config)
                ? preg_replace("/(['\"])token\\1\\s*=>\\s*(['\"])[^'\"]*\\2/", "'token' => '".$token."'", $config, 1, $count)
                : null;
            if (! is_string($updated) || $count !== 1 || ! $zip->addFromString($entry, $updated)) {
                throw new RuntimeException('Migrator install zip has no token in '.$entry);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Download the configured (or given) release URL, refuse it unless it is
     * the pinned release, and repack it for install.
     */
    public function downloadAndRepack(?string $releaseUrl = null): string
    {
        $url = $releaseUrl ?? (string) config('services.wordpress_migrator.release_url');

        $tmpDir = sys_get_temp_dir().'/wp-migrator-'.bin2hex(random_bytes(8));
        if (! mkdir($tmpDir, 0700, true) && ! is_dir($tmpDir)) {
            throw new RuntimeException('Could not create temporary directory for migrator zip');
        }

        $tagZip = $tmpDir.'/tag.zip';
        try {
            $this->download($url, $tagZip);
            self::assertPinnedRelease($tagZip, $url);

            return $this->repackFromTagArchive($tagZip, $tmpDir);
        } catch (Throwable $e) {
            self::removeDir($tmpDir);
            throw $e;
        }
    }

    private function download(string $url, string $into): void
    {
        try {
            $response = (new BoundedDownload(self::MAX_RELEASE_BYTES, followRedirects: true, timeoutSeconds: $this->downloadSeconds))->fetch($url, $into);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to download WordPress migrator release: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to download WordPress migrator release (HTTP '.$response->status().'): '.$url
            );
        }
    }

    private static function assertPinnedRelease(string $releaseZip, string $url): void
    {
        $expected = strtolower(trim((string) config('services.wordpress_migrator.sha256')));
        $actual = (string) hash_file('sha256', $releaseZip);
        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException(
                "The WordPress migrator release at {$url} is not the pinned one: its SHA-256 is {$actual}, "
                ."but WORDPRESS_MIGRATOR_SHA256 is '{$expected}'"
            );
        }
    }

    /**
     * Whether this install zip is a release whose transfer routes must refuse
     * a call without the site's token; false when it names no version.
     */
    public static function requiresToken(string $installZip): bool
    {
        $version = self::versionOf($installZip);

        return $version !== null && version_compare($version, self::AUTHENTICATED_VERSION, '>=');
    }

    /**
     * The `Version:` header of the plugin's main file, as WordPress reads it.
     */
    private static function versionOf(string $installZip): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($installZip) !== true) {
            return null;
        }
        $main = $zip->getFromName(self::PLUGIN_DIR.'/'.self::REQUIRED_ENTRY);
        $zip->close();

        return is_string($main) && preg_match('/^[ \t\/*#@]*Version:[ \t]*([0-9][0-9A-Za-z.+-]*)/mi', $main, $match) === 1
            ? $match[1]
            : null;
    }

    /**
     * Repack a local tag-archive zip (or directory layout) into an install zip.
     * Used by unit tests with a fixture — no network.
     *
     * @return string absolute path to the install zip
     */
    public function repackFromTagArchive(string $tagArchivePath, ?string $workDir = null): string
    {
        if (! is_file($tagArchivePath)) {
            throw new RuntimeException("Migrator tag archive not found: {$tagArchivePath}");
        }

        $ownWorkDir = $workDir === null;
        $workDir ??= sys_get_temp_dir().'/wp-migrator-repack-'.bin2hex(random_bytes(8));
        if ($ownWorkDir && ! mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            throw new RuntimeException('Could not create temporary directory for migrator repack');
        }

        $extractDir = $workDir.'/extracted';
        if (! mkdir($extractDir, 0700, true) && ! is_dir($extractDir)) {
            if ($ownWorkDir) {
                self::removeDir($workDir);
            }
            throw new RuntimeException('Could not create extract directory for migrator archive');
        }

        $zip = new ZipArchive;
        if ($zip->open($tagArchivePath) !== true) {
            if ($ownWorkDir) {
                self::removeDir($workDir);
            }
            throw new RuntimeException('Could not open migrator tag archive');
        }
        try {
            self::extractSafely($zip, $extractDir);
        } catch (RuntimeException $e) {
            $zip->close();
            if ($ownWorkDir) {
                self::removeDir($workDir);
            }
            throw $e;
        }
        $zip->close();

        $pluginSource = self::findPluginSourceRoot($extractDir);
        $main = $pluginSource.'/'.self::REQUIRED_ENTRY;
        if (! is_file($main)) {
            if ($ownWorkDir) {
                self::removeDir($workDir);
            }
            throw new RuntimeException(
                'Migrator tag archive is missing '.self::REQUIRED_ENTRY
                .' under the top-level directory'
            );
        }

        $installZip = $workDir.'/wordpress-migrator-install.zip';
        $out = new ZipArchive;
        if ($out->open($installZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            if ($ownWorkDir) {
                self::removeDir($workDir);
            }
            throw new RuntimeException('Could not create migrator install zip');
        }

        self::addDirectoryToZip($out, $pluginSource, self::PLUGIN_DIR);
        $out->close();

        if (! self::installZipLooksValid($installZip)) {
            if ($ownWorkDir) {
                self::removeDir($workDir);
            } else {
                @unlink($installZip);
            }
            throw new RuntimeException(
                'Migrator install zip has the wrong layout after repack '
                .'(expected '.self::PLUGIN_DIR.'/'.self::REQUIRED_ENTRY.')'
            );
        }

        return $installZip;
    }

    /**
     * Extract only relative entries that stay under $extractDir.
     * Absolute paths and `..` segments are rejected before any write.
     */
    private static function extractSafely(ZipArchive $zip, string $extractDir): void
    {
        $root = realpath($extractDir);
        if ($root === false) {
            throw new RuntimeException('Could not resolve migrator extract directory');
        }
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Migrator tag archive has an unreadable entry');
            }

            $relative = self::safeRelativeEntry($name);
            $target = $rootPrefix.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($relative === '' || str_ends_with($name, '/') || str_ends_with($name, '\\')) {
                if (! is_dir($target) && ! mkdir($target, 0700, true) && ! is_dir($target)) {
                    throw new RuntimeException('Could not create directory from migrator archive');
                }

                continue;
            }

            $parent = dirname($target);
            if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                throw new RuntimeException('Could not create directory from migrator archive');
            }

            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                throw new RuntimeException('Could not read migrator archive entry');
            }
            if (file_put_contents($target, $contents) === false) {
                throw new RuntimeException('Could not write migrator archive entry');
            }

            $written = realpath($target);
            if ($written === false || ! str_starts_with($written, $rootPrefix)) {
                @unlink($target);
                throw new RuntimeException('Migrator tag archive contains an unsafe path');
            }
        }
    }

    private static function safeRelativeEntry(string $name): string
    {
        $normalized = str_replace('\\', '/', $name);
        if (str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('#^[a-zA-Z]:#', $normalized) === 1
        ) {
            throw new RuntimeException('Migrator tag archive contains an unsafe path');
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new RuntimeException('Migrator tag archive contains an unsafe path');
            }
        }

        return ltrim($normalized, '/');
    }

    /**
     * Top-level directory inside the tag archive (e.g. panelalpha-wordpress-migrator-1.1.4/).
     */
    private static function findPluginSourceRoot(string $extractDir): string
    {
        $entries = scandir($extractDir);
        if ($entries === false) {
            throw new RuntimeException('Could not list extracted migrator archive');
        }

        $dirs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $extractDir.'/'.$entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        if (count($dirs) === 1) {
            return $dirs[0];
        }

        if (self::isFlatExtract($extractDir)) {
            return $extractDir;
        }

        throw new RuntimeException(
            'Migrator tag archive must contain a single top-level directory '
            .'with '.self::REQUIRED_ENTRY
        );
    }

    /** An archive with no wrapper directory: its extract root is the plugin source. */
    private static function isFlatExtract(string $extractDir): bool
    {
        return is_file($extractDir.'/'.self::REQUIRED_ENTRY);
    }

    private static function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($sourceDir) + 1);
            $relative = str_replace('\\', '/', $relative);
            $base = basename($relative);
            if (in_array($base, self::EXCLUDED, true)) {
                continue;
            }

            $entryName = $prefix.'/'.$relative;
            if ($file->isDir()) {
                $zip->addEmptyDir(rtrim($entryName, '/').'/');

                continue;
            }

            $zip->addFile($file->getPathname(), $entryName);
        }
    }

    private static function installZipLooksValid(string $zipPath): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        $hasMain = $zip->locateName(self::PLUGIN_DIR.'/'.self::REQUIRED_ENTRY) !== false;
        $hasBranding = $zip->locateName(self::PLUGIN_DIR.'/branding.php') !== false
            || $zip->locateName('branding.php') !== false;
        $zip->close();

        return $hasMain && ! $hasBranding;
    }

    private static function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}

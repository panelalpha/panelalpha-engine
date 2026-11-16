<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\System;
use App\System\Privileged;

/**
 * Where a WordPress on PHP hosting is, read as root: a directory in the home holding wp-includes/version.php, and the
 * wp-config.php WordPress loads for it, its own or the one in the directory above when that holds no other WordPress.
 * A search below the document root lists directories without following a link out of them.
 */
final class InstallLocator
{
    private const CORE_DIR = 'wp-includes';

    private const CORE_FILE = self::CORE_DIR.'/version.php';

    private const CONFIG_FILE = 'wp-config.php';

    private const SEARCH_DEPTH = 3;

    private const NOT_SEARCHED = ['wp-admin', 'wp-content', 'wp-includes', 'vendor', 'node_modules', '.git'];

    public function __construct(private readonly System $system) {}

    /**
     * The install at the recorded directory or the document root, else the only one up to three levels below the
     * document root.
     *
     * @throws UnclearInstall when several are below it, or a wp-config.php there is loaded by none of them
     */
    public function find(string $home, string $documentRoot, ?string $recorded): ?Install
    {
        $documentRoot = rtrim($documentRoot, '/');
        foreach (array_filter([$recorded, $documentRoot], static fn (?string $dir): bool => $dir !== null) as $dir) {
            $install = $this->at($home, $dir);
            if ($install !== null) {
                return $install;
            }
        }

        return $this->onlyOneBelow($home, $documentRoot);
    }

    /** The install whose WordPress directory is the one given, or null when it holds no configured WordPress. */
    public function at(string $home, string $dir): ?Install
    {
        $dir = rtrim($dir, '/');
        if (! self::isBelow($dir, $home) || ! $this->exists($dir.'/'.self::CORE_FILE)) {
            return null;
        }
        $config = $this->configOf($home, $dir);

        return $config === null ? null : new Install($dir, $config);
    }

    private function onlyOneBelow(string $home, string $documentRoot): ?Install
    {
        $found = ['dirs' => [], 'configs' => $this->configAbove($home, $documentRoot)];
        $this->search($documentRoot, 0, $found);
        $installs = array_values(array_filter(array_map(fn (string $dir): ?Install => $this->at($home, $dir), $found['dirs'])));
        if (count($installs) > 1) {
            throw UnclearInstall::several($documentRoot, $installs);
        }
        $loaded = array_map(static fn (Install $install): string => $install->configPath, $installs);
        $unloaded = array_values(array_diff($found['configs'], $loaded));
        if ($unloaded !== []) {
            throw UnclearInstall::unloadedConfig($unloaded[0]);
        }

        return $installs[0] ?? null;
    }

    /** @return list<string> */
    private function configAbove(string $home, string $documentRoot): array
    {
        $config = dirname($documentRoot).'/'.self::CONFIG_FILE;

        return self::isBelow(dirname($documentRoot), $home) && $this->exists($config) ? [$config] : [];
    }

    /** @param  array{dirs: list<string>, configs: list<string>}  $found */
    private function search(string $dir, int $depth, array &$found): void
    {
        foreach ($this->entries($dir) as [$type, $name]) {
            if ($name === self::CONFIG_FILE && $type !== 'd') {
                $found['configs'][] = $dir.'/'.$name;
            }
            if ($type !== 'd') {
                continue;
            }
            if ($name === self::CORE_DIR) {
                $found['dirs'][] = $dir;
            }
            if ($depth < self::SEARCH_DEPTH && ! in_array($name, self::NOT_SEARCHED, true)) {
                $this->search($dir.'/'.$name, $depth + 1, $found);
            }
        }
    }

    /** @return list<array{string, string}> find's type letter and the name of each entry */
    private function entries(string $dir): array
    {
        $process = $this->system->runProcess(Privileged::files()->listEntryTypes($dir));
        if (! $process->isSuccessful()) {
            return [];
        }

        $entries = [];
        foreach (explode("\0", $process->getOutput()) as $record) {
            [$type, $name] = array_pad(explode(' ', $record, 2), 2, '');
            if ($name !== '') {
                $entries[] = [$type, $name];
            }
        }

        return $entries;
    }

    private function configOf(string $home, string $dir): ?string
    {
        if ($this->exists($dir.'/'.self::CONFIG_FILE)) {
            return $dir.'/'.self::CONFIG_FILE;
        }
        $parent = dirname($dir);
        if (self::isBelow($parent, $home) && $this->exists($parent.'/'.self::CONFIG_FILE) && ! $this->exists($parent.'/wp-settings.php')) {
            return $parent.'/'.self::CONFIG_FILE;
        }

        return null;
    }

    private function exists(string $path): bool
    {
        return $this->system->runProcess(Privileged::files()->exists($path))->isSuccessful();
    }

    private static function isBelow(string $path, string $home): bool
    {
        return str_starts_with($path, rtrim($home, '/').'/');
    }
}

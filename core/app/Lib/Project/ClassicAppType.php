<?php

namespace App\Lib\Project;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Privileged;
use App\System\Projects;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a PHP hosting project is, stored so a list never looks inside an account: wordpress when its main docroot or the
 * directory it was installed into holds WordPress core, otherwise php. Read in one host shell run; a project whose home
 * is missing keeps its type.
 */
class ClassicAppType
{
    public const WORDPRESS = 'wordpress';

    public const PHP = 'php';

    /** @var callable(list<string>): string */
    private $runOnHost;

    /** @param (callable(list<string>): string)|null $runOnHost runs a host argv, returns stdout */
    public function __construct(?callable $runOnHost = null)
    {
        $this->runOnHost = $runOnHost ?? fn (array $argv): string => (new System)->exec($argv);
    }

    /** @return int projects whose stored type or install path changed */
    public function sweep(): int
    {
        return $this->store($this->docroots(User::query()->get()->all()));
    }

    public function refresh(User $user): void
    {
        $this->store($this->docroots([$user]));
    }

    /** The stored type, or null for a container project or one not read yet. */
    public static function of(User $user): ?string
    {
        $type = $user->getAttribute('app_type');

        return in_array($type, [self::WORDPRESS, self::PHP], true) ? $type : null;
    }

    /**
     * The directory below the home a WordPress was installed into, or null when it is the main domain's document root.
     */
    public static function installPathOf(User $user): ?string
    {
        $path = $user->getAttribute('app_path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Stores the directory WordPress was installed into and reads the type again; the main domain's document root needs
     * no path, so it is recorded as none. Both are compared without doubled or trailing slashes.
     */
    public function recordInstall(User $user, string $path): void
    {
        $docroots = $this->docroots([$user]);
        $docroot = $docroots[$user->username]['docroot'] ?? null;
        if ($docroot === null) {
            return;
        }

        $path = self::normalisedPath($path);
        $this->writeInstallPath($user, $path === self::normalisedPath($docroot) ? null : $path);
        $this->refresh($user);
    }

    private static function normalisedPath(string $path): string
    {
        $path = (string) preg_replace('~/+~', '/', $path);

        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * @return array<string, array{type: string, path: string}> username => type and the install directory below the
     *                                                          home, empty for the main docroot or nothing
     */
    public static function parse(string $out): array
    {
        $found = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) >= 3 && $fields[0] === 'T' && in_array($fields[2], [self::WORDPRESS, self::PHP], true)) {
                $found[$fields[1]] = ['type' => $fields[2], 'path' => $fields[3] ?? ''];
            }
        }

        return $found;
    }

    /**
     * @param  list<User>  $users
     * @return array<string, array{home: string, docroot: string, install: string}>
     */
    private function docroots(array $users): array
    {
        $classic = array_values(array_filter($users, fn (User $user): bool => ! Projects::isDind($user)));
        $mainDomains = $this->mainDomains($classic);
        $system = new System;
        $docroots = [];
        foreach ($classic as $user) {
            $domain = $mainDomains[$user->id] ?? null;
            if ($domain !== null && is_string($user->username) && $user->username !== '') {
                $home = rtrim($system->projectHomeDirPath($user->username), '/');
                $docroots[$user->username] = [
                    'home' => $home,
                    'docroot' => $home.$domain->getDocumentRoot(),
                    'install' => self::installPathOf($user) ?? '',
                ];
            }
        }

        return $docroots;
    }

    /**
     * @param  list<User>  $users
     * @return array<int, Domain>
     */
    private function mainDomains(array $users): array
    {
        if ($users === []) {
            return [];
        }

        /** @var array<int, Domain> */
        return Domain::query()
            ->where('type', 'main')
            ->whereIn('user_id', array_map(fn (User $user): int => (int) $user->id, $users))
            ->get()
            ->keyBy('user_id')
            ->all();
    }

    /** @param  array<string, array{home: string, docroot: string, install: string}>  $docroots */
    private function store(array $docroots): int
    {
        if ($docroots === []) {
            return 0;
        }

        try {
            $found = array_intersect_key(self::parse(($this->runOnHost)(Privileged::host()->classicAppTypes($docroots))), $docroots);
        } catch (Throwable $e) {
            Log::warning('Could not read the type of PHP hosting projects: '.$e->getMessage());

            return 0;
        }

        $changed = 0;
        foreach ($found as $username => $facts) {
            $changed += $this->storeOne($username, $facts['type'], $facts['path']);
        }

        return $changed;
    }

    private function storeOne(string $username, string $type, string $install): int
    {
        $path = $install === '' ? null : $install;
        $stored = DB::table('users')->where('username', $username)->first(['app_type', 'app_path']);
        if ($stored === null || ($stored->app_type === $type && $stored->app_path === $path)) {
            return 0;
        }

        return DB::table('users')->where('username', $username)->update(['app_type' => $type, 'app_path' => $path]);
    }

    /** Stores the install directory, on the model too, so the read that follows sees it. */
    private function writeInstallPath(User $user, ?string $path): void
    {
        if ($user->getAttribute('app_path') !== $path) {
            DB::table('users')->where('id', $user->id)->update(['app_path' => $path]);
        }
        $user->setAttribute('app_path', $path);
    }
}

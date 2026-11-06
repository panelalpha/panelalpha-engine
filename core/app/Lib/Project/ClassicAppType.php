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
 * What a PHP hosting project is, stored so a list never looks inside an account: wordpress when its main docroot
 * holds WordPress core, otherwise php. Read in one host shell run; a project whose home is missing keeps its type.
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

    /** @return int projects whose stored type changed */
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

    /** @return array<string, string> username => type */
    public static function parse(string $out): array
    {
        $types = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) === 3 && $fields[0] === 'T' && in_array($fields[2], [self::WORDPRESS, self::PHP], true)) {
                $types[$fields[1]] = $fields[2];
            }
        }

        return $types;
    }

    /**
     * @param  list<User>  $users
     * @return array<string, array{home: string, docroot: string}>
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
                $docroots[$user->username] = ['home' => $home, 'docroot' => $home.$domain->getDocumentRoot()];
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

    /** @param  array<string, array{home: string, docroot: string}>  $docroots */
    private function store(array $docroots): int
    {
        if ($docroots === []) {
            return 0;
        }

        try {
            $types = array_intersect_key(self::parse(($this->runOnHost)(Privileged::host()->classicAppTypes($docroots))), $docroots);
        } catch (Throwable $e) {
            Log::warning('Could not read the type of PHP hosting projects: '.$e->getMessage());

            return 0;
        }

        $changed = 0;
        foreach ($types as $username => $type) {
            $changed += $this->storeOne($username, $type);
        }

        return $changed;
    }

    private function storeOne(string $username, string $type): int
    {
        return DB::table('users')
            ->where('username', $username)
            ->where(fn ($query) => $query->whereNull('app_type')->orWhere('app_type', '!=', $type))
            ->update(['app_type' => $type]);
    }
}

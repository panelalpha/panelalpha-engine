<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Models\User;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Strategy\ManifestDatabase;

/**
 * A run file a staging, clone or push brought over from another project.
 *
 * The deploy wrote that project's application database credentials, its
 * public address and the account uid:gid its containers run as into the file
 * as literal values, so the copy would run on the other project's database,
 * answer as its domain and own none of its files. Every value the
 * deploy derived from the source is replaced with the one it derives for this
 * project, through the same code; a value the project set for itself is left
 * as it is, because it never equals one the engine derived.
 */
final class CopiedRunFile
{
    public function __construct(private DindProject $project) {}

    /**
     * @return bool whether the file changed
     */
    public function repointFrom(User $source, string $path): bool
    {
        (new CopiedAccountUserFiles($this->project))->repointFrom($source, dirname($path));
        (new CopiedApiAccess($this->project))->repointIn($path);
        $database = $this->databaseValues($source);
        $address = $this->addressValues($source);
        $identity = $this->identityValues($source);
        if ($database === [] && $address === [] && $identity === []) {
            return false;
        }
        $fs = $this->project->system()->filesystem();
        if (! $fs->fileExists($path)) {
            return false;
        }
        $fs->assertNotSymlink($path);
        $raw = $fs->fileGetContents($path);
        $compose = ComposeYaml::parse($raw);
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : null;
        if ($compose === null || $services === null) {
            return false;
        }
        $repointed = $this->repointedServices($services, $database, $address, $identity);
        if ($repointed === $services) {
            return false;
        }
        $compose['services'] = $repointed;
        $fs->writeFileReplacingPath(
            $path,
            ComposeYaml::dump($compose, $raw, 6, 2),
            $this->project->userModel()->getChownString(),
            EngineArtifacts::RUN_COMPOSE_MODE
        );

        return true;
    }

    /**
     * The source's database variables against this project's, by name, each
     * as written and as Compose-escaped.
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private function databaseValues(User $source): array
    {
        $from = AppDatabase::credentialsOf($source);
        $to = AppDatabase::credentialsOf($this->project->userModel());
        if ($from === null || $to === null) {
            return [];
        }
        $fromEnv = ManifestDatabase::environment($from);
        $toEnv = ManifestDatabase::environment($to);
        $fromEscaped = ComposeEnvironment::escapedForCompose($fromEnv);
        $toEscaped = ComposeEnvironment::escapedForCompose($toEnv);
        $values = [];
        foreach ($fromEnv as $key => $value) {
            if ($value !== $toEnv[$key]) {
                $values[$key] = [[$value, $toEnv[$key]], [$fromEscaped[$key], $toEscaped[$key]]];
            }
        }

        return $values;
    }

    /**
     * The source's public URL, under either scheme, and its bare host, against
     * this project's.
     *
     * @return array<string, string>
     */
    private function addressValues(User $source): array
    {
        $from = Networking::publicUrlOf($source);
        $to = Networking::publicUrlOf($this->project->userModel());
        $fromHost = is_string($from) ? (string) parse_url($from, PHP_URL_HOST) : '';
        $toHost = is_string($to) ? (string) parse_url($to, PHP_URL_HOST) : '';
        if ($fromHost === '' || $toHost === '' || $fromHost === $toHost) {
            return [];
        }

        return [
            "https://{$fromHost}" => (string) $to,
            "http://{$fromHost}" => (string) $to,
            $fromHost => $toHost,
        ];
    }

    /**
     * The source account's `uid:gid`, and its bare uid, against this
     * project's: what a generated service's `user:` holds.
     *
     * @return array<string, string>
     */
    private function identityValues(User $source): array
    {
        $to = $this->project->userModel();
        $ids = [$source->getUid(), $source->getGid(), $to->getUid(), $to->getGid()];
        if (in_array(null, $ids, true) || [$ids[0], $ids[1]] === [$ids[2], $ids[3]]) {
            return [];
        }

        return ["{$ids[0]}:{$ids[1]}" => "{$ids[2]}:{$ids[3]}", (string) $ids[0] => (string) $ids[2]];
    }

    /**
     * @param  array<mixed>  $services
     * @param  array<string, list<array{0: string, 1: string}>>  $database
     * @param  array<string, string>  $address
     * @param  array<string, string>  $identity
     * @return array<mixed>
     */
    private function repointedServices(array $services, array $database, array $address, array $identity): array
    {
        $pin = $database === [] ? null : $this->databasePin();
        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            if (is_array($service['environment'] ?? null)) {
                $service['environment'] = self::repointedEnvironment($service['environment'], $database, $address);
            }
            if ($pin !== null && is_array($service['extra_hosts'] ?? null)) {
                $service['extra_hosts'] = self::repinnedHosts($service['extra_hosts'], $pin);
            }
            if ((is_string($service['user'] ?? null) || is_int($service['user'] ?? null))
                && array_key_exists((string) $service['user'], $identity)
            ) {
                $service['user'] = $identity[(string) $service['user']];
            }
            $services[$name] = $service;
        }

        return $services;
    }

    /**
     * The `extra_hosts` entry this account resolves the database name with,
     * which depends on the network the account is on.
     */
    private function databasePin(): ?string
    {
        return AppDatabase::extraHosts($this->project)[0] ?? null;
    }

    /**
     * @param  array<mixed>  $environment
     * @param  array<string, list<array{0: string, 1: string}>>  $database
     * @param  array<string, string>  $address
     * @return array<mixed>
     */
    private static function repointedEnvironment(array $environment, array $database, array $address): array
    {
        if (array_is_list($environment)) {
            return array_map(
                static fn (mixed $entry): mixed => is_string($entry) ? self::repointedEntry($entry, $database, $address) : $entry,
                $environment
            );
        }
        foreach ($environment as $key => $value) {
            if (is_string($value)) {
                $environment[$key] = self::repointedValue((string) $key, $value, $database, $address);
            }
        }

        return $environment;
    }

    /**
     * @param  array<string, list<array{0: string, 1: string}>>  $database
     * @param  array<string, string>  $address
     */
    private static function repointedEntry(string $entry, array $database, array $address): string
    {
        $separator = strpos($entry, '=');
        if ($separator === false) {
            return $entry;
        }
        $key = substr($entry, 0, $separator);

        return $key.'='.self::repointedValue($key, substr($entry, $separator + 1), $database, $address);
    }

    /**
     * @param  array<string, list<array{0: string, 1: string}>>  $database
     * @param  array<string, string>  $address
     */
    private static function repointedValue(string $key, string $value, array $database, array $address): string
    {
        foreach ($database[$key] ?? [] as [$from, $to]) {
            if ($value === $from) {
                return $to;
            }
        }

        return self::repointedAddress($value, $address);
    }

    /**
     * @param  array<string, string>  $address
     */
    private static function repointedAddress(string $value, array $address): string
    {
        foreach ($address as $from => $to) {
            if ($value === $from) {
                return $to;
            }
            if (str_starts_with($value, $from.'/')) {
                return $to.substr($value, strlen($from));
            }
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $hosts  a list of `name:address` or a name => address map
     * @return array<mixed>
     */
    private static function repinnedHosts(array $hosts, string $pin): array
    {
        [$name, $address] = explode(':', $pin, 2);
        if (! array_is_list($hosts)) {
            return array_key_exists($name, $hosts) ? array_replace($hosts, [$name => $address]) : $hosts;
        }

        return array_map(
            static fn (mixed $entry): mixed => is_string($entry) && str_starts_with($entry, $name.':') ? $pin : $entry,
            $hosts
        );
    }
}

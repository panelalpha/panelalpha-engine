<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\ServiceRole;

/**
 * A datastore that reads a credential from a secret file (`MYSQL_PASSWORD_FILE: /run/secrets/mysql-password`,
 * the secret's `file: docker/secrets/mysql-password`) gets the file's value as the plain variable instead: the
 * engine's credentials, connection settings and healthcheck all read the variable, and the official images
 * refuse it set both ways. A file the repository leaves out gets the value {@see SecretSourceFiles} writes. Only a
 * datastore's: any other service reads its file as written, and nothing of the engine's reads its variables.
 *
 * No Laravel dependencies — unit-testable.
 */
final class SecretFileEnvironment
{
    private const SECRETS_DIR = '/run/secrets/';

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $declared  the file's top-level `secrets:`
     * @param  callable(string): ?string  $read  a checkout file by its relative path, null when it does not exist
     * @param  callable(string): string  $generated  the value written for a secret whose file is missing
     * @return array<string, mixed>
     */
    public static function inlined(string $name, array $service, array $declared, callable $read, callable $generated): array
    {
        $environment = self::mapOf($service['environment'] ?? null);
        if ($environment === [] || ! ServiceRole::isKnownDatastore($name, $service)) {
            return $service;
        }
        $inlined = false;
        foreach ($environment as $key => $value) {
            $variable = self::variableOf((string) $key);
            if ($variable === null || array_key_exists($variable, $environment) || ! is_string($value)) {
                continue;
            }
            $contents = self::secretContents($service, $value, $declared, $read, $generated);
            if ($contents !== null) {
                unset($environment[$key]);
                $environment[$variable] = str_replace('$', '$$', $contents);
                $inlined = true;
            }
        }
        if ($inlined) {
            $service['environment'] = $environment;
        }

        return $service;
    }

    /**
     * `environment:` as a map, the `- KEY=value` form included; a listed `- KEY` passes through as `KEY: null` does.
     *
     * @return array<string, mixed>
     */
    private static function mapOf(mixed $environment): array
    {
        if (! is_array($environment)) {
            return [];
        }
        if (! array_is_list($environment)) {
            return $environment;
        }
        $map = [];
        foreach ($environment as $entry) {
            if (is_string($entry) && str_contains($entry, '=')) {
                [$key, $value] = explode('=', $entry, 2);
                $map[trim($key)] = $value;
            } elseif (is_string($entry) && trim($entry) !== '') {
                $map[trim($entry)] = null;
            }
        }

        return $map;
    }

    private static function variableOf(string $key): ?string
    {
        return preg_match('/^([A-Z][A-Z0-9_]*)_FILE$/', $key, $m) === 1 ? $m[1] : null;
    }

    /**
     * The value of the secret mounted at $path, when the service names it and its file is in the checkout.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $declared
     * @param  callable(string): ?string  $read
     * @param  callable(string): string  $generated
     */
    private static function secretContents(array $service, string $path, array $declared, callable $read, callable $generated): ?string
    {
        $name = self::mountedAt($service, trim($path));
        $file = $name === null ? null : ($declared[$name]['file'] ?? null);
        if (! is_string($file) || ! SecretSourceFiles::isCheckoutPath($file)) {
            return null;
        }
        $contents = $read(trim($file));
        if ($contents === null) {
            $value = $generated((string) $name);

            return $value !== '' ? $value : null;
        }

        return trim($contents) !== '' ? rtrim($contents, "\r\n") : null;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function mountedAt(array $service, string $path): ?string
    {
        foreach (ComposeFileReferences::listed($service, 'secrets') as $reference) {
            $name = ComposeFileReferences::nameOf($reference);
            $target = is_array($reference) && is_string($reference['target'] ?? null) ? $reference['target'] : $name;
            if ($name !== null && is_string($target) && self::targetPath($target) === $path) {
                return $name;
            }
        }

        return null;
    }

    private static function targetPath(string $target): string
    {
        return str_starts_with($target, '/') ? $target : self::SECRETS_DIR.$target;
    }
}

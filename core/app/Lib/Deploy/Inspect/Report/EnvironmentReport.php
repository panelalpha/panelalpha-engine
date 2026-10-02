<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\Env\EnvTemplates;
use App\Lib\Deploy\EnvFile;

/**
 * Variable *names* the project expects, and the defaults the platform sets.
 *
 * Names only. A committed .env is a mistake people make, and an inspection
 * endpoint that echoed one back would turn that mistake into a disclosure.
 */
final class EnvironmentReport
{
    /** A generated file can hold thousands of keys. */
    public const MAX_KEYS = 300;

    /**
     * Both orderings of the same idea.
     *
     * `.env.example` is the common spelling, but the words also get written
     * the other way round, and a name this report does not know is a variable
     * it cannot tell anyone about. Kutt ships `.example.env`, 3045 bytes of
     * it, whose eleventh line is `JWT_SECRET=`; the report came back
     * `files: []`, `variables: []`, and the deploy then restart-looped on
     *
     *     Missing environment variables:
     *        JWT_SECRET: undefined
     *
     * -- the one variable that sinks the deploy, invisible in the report
     * whose whole job is to name them.
     *
     * @var list<string>
     */
    private const CANDIDATE_FILES = [...EnvTemplates::NAMES, '.env'];

    private readonly string $projectDir;

    /**
     * @param array<string, mixed> $decision
     */
    public function __construct(string $projectDir, private readonly array $decision)
    {
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * @param array<string, mixed> $decision
     * @return array{files: list<string>, variables: list<string>, variables_truncated: bool, defaults: array<string, mixed>}
     */
    public static function of(string $projectDir, array $decision): array
    {
        return (new self($projectDir, $decision))->build();
    }

    /**
     * @return array{files: list<string>, variables: list<string>, variables_truncated: bool, defaults: array<string, mixed>}
     */
    public function build(): array
    {
        $files = [];
        $keys = [];
        foreach (self::CANDIDATE_FILES as $name) {
            $contents = $this->contents($name);
            if ($contents === null) {
                continue;
            }
            $files[] = $name;
            $keys = array_merge($keys, self::keysIn($contents));
        }

        $keys = array_values(array_unique($keys));
        sort($keys);
        $defaults = $this->decision['env'] ?? null;

        return [
            'files' => $files,
            'variables' => array_slice($keys, 0, self::MAX_KEYS),
            'variables_truncated' => count($keys) > self::MAX_KEYS,
            'defaults' => is_array($defaults) ? $defaults : [],
        ];
    }

    private function contents(string $name): ?string
    {
        $path = $this->projectDir . '/' . $name;
        $contents = is_file($path) ? @file_get_contents($path) : null;

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * @return list<string>
     */
    private static function keysIn(string $contents): array
    {
        $keys = [];
        foreach (EnvFile::parse($contents) as $row) {
            $key = (string) ($row['key'] ?? '');
            if (($row['type'] ?? '') === 'variable' && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}

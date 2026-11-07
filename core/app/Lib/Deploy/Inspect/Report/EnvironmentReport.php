<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Env\EnvTemplates;
use App\Lib\Deploy\EnvFile;

/**
 * Variable *names* the project expects, and the defaults the platform sets. Names only: echoing a
 * committed .env back would turn that mistake into a disclosure.
 */
final class EnvironmentReport
{
    /** A generated file can hold thousands of keys. */
    public const MAX_KEYS = 300;

    /**
     * Every template spelling plus `.env`: a template the report does not know, like Kutt's `.example.env`
     * with its `JWT_SECRET=`, hides the very variable that sinks the deploy.
     *
     * @var list<string>
     */
    private const CANDIDATE_FILES = [...EnvTemplates::NAMES, '.env'];

    private readonly string $projectDir;

    /**
     * @param  array<string, mixed>  $decision
     */
    public function __construct(string $projectDir, private readonly array $decision)
    {
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * @param  array<string, mixed>  $decision
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
        $path = $this->projectDir.'/'.$name;
        $contents = is_file($path) ? AccountHomeRead::contents($path) : null;

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

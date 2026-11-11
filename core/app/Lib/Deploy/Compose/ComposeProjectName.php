<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\EnvFile;

/**
 * The project name compose gives a stack: COMPOSE_PROJECT_NAME, else the last top-level `name:` interpolated and made
 * valid, else the project directory's name made valid. Where compose refuses the name, so does this.
 */
final class ComposeProjectName
{
    public const VARIABLE = 'COMPOSE_PROJECT_NAME';

    private const REFERENCE = '/\$(?:\$|\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-+?])((?:[^{}]|\{(?3)\})*))?\}|([A-Za-z_][A-Za-z0-9_]*))/';

    /**
     * @param  list<string>  $composeFiles  each file's contents, in `-f` order
     * @param  list<string>  $envFiles  each env file's contents, in `--env-file` order
     * @param  array<string, string>  $processEnvironment  the compose client's own environment, which wins over the env files
     *
     * @throws \InvalidArgumentException with compose's reason, where compose refuses the name
     */
    public static function of(string $projectDirectory, array $composeFiles, array $envFiles, array $processEnvironment = []): string
    {
        $environment = $processEnvironment + self::environment($envFiles);
        if (($environment[self::VARIABLE] ?? '') !== '') {
            return self::checked($environment[self::VARIABLE]);
        }
        $name = self::valid(self::declared($composeFiles, $environment));
        $name = $name !== '' ? $name : self::fromDirectory($projectDirectory);
        if ($name === '') {
            throw new \InvalidArgumentException('project name must not be empty');
        }

        return $name;
    }

    /** COMPOSE_PROJECT_NAME is taken as written, and refused unless it is valid already. */
    private static function checked(string $name): string
    {
        if (self::valid($name) !== $name) {
            throw new \InvalidArgumentException('invalid project name '.json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                .': must consist only of lowercase alphanumeric characters, hyphens, and underscores as well as start with a letter or number');
        }

        return $name;
    }

    /**
     * The last file's `name:` as written that is not empty, interpolated. Every file's name is interpolated, as
     * compose interpolates each whole file, so one that fails or is no string refuses the name.
     *
     * @param  list<string>  $composeFiles
     * @param  array<string, string>  $environment
     */
    private static function declared(array $composeFiles, array $environment): string
    {
        $name = '';
        foreach ($composeFiles as $contents) {
            $declared = ComposeYaml::parse($contents)['name'] ?? null;
            if ($declared === null) {
                continue;
            }
            if (! is_string($declared)) {
                throw new \InvalidArgumentException('name must be a string');
            }
            $interpolated = self::interpolated($declared, $environment);
            if ($declared !== '') {
                $name = $interpolated;
            }
        }

        return $name;
    }

    private static function fromDirectory(string $directory): string
    {
        return self::valid(basename(rtrim($directory, '/')));
    }

    /** Lower case, only letters, digits, `_` and `-`, and starting with neither of the last two. */
    private static function valid(string $name): string
    {
        return ltrim((string) preg_replace('/[^a-z0-9_-]/', '', strtolower($name)), '_-');
    }

    /**
     * Later files win, as with several `--env-file`.
     *
     * @param  list<string>  $envFiles
     * @return array<string, string>
     */
    private static function environment(array $envFiles): array
    {
        $environment = [];
        foreach ($envFiles as $contents) {
            foreach (EnvFile::parse($contents) as $row) {
                if (($row['type'] ?? null) === 'variable') {
                    $environment[(string) $row['key']] = (string) $row['value'];
                }
            }
        }

        return $environment;
    }

    /**
     * `$VAR`, `${VAR}` and `${VAR:-word}`, `${VAR-word}`, `${VAR:+word}`, `${VAR+word}`, `${VAR:?word}`,
     * `${VAR?word}`, with references in the word, and `$$`; an unset variable is empty.
     *
     * @param  array<string, string>  $environment
     *
     * @throws \InvalidArgumentException for a required variable that is missing
     */
    private static function interpolated(string $value, array $environment): string
    {
        return (string) preg_replace_callback(self::REFERENCE, static function (array $m) use ($environment): string {
            if ($m[0] === '$$') {
                return '$';
            }
            $variable = ($m[4] ?? '') !== '' ? $m[4] : $m[1];
            $set = array_key_exists($variable, $environment);
            $current = $environment[$variable] ?? '';
            $word = self::interpolated($m[3] ?? '', $environment);

            return match ($m[2] ?? '') {
                ':-' => $current === '' ? $word : $current,
                '-' => $set ? $current : $word,
                ':+' => $current === '' ? '' : $word,
                '+' => $set ? $word : '',
                ':?' => $current === '' ? throw self::missing($variable, $word) : $current,
                '?' => $set ? $current : throw self::missing($variable, $word),
                default => $current,
            };
        }, $value);
    }

    private static function missing(string $variable, string $reason): \InvalidArgumentException
    {
        return new \InvalidArgumentException("required variable {$variable} is missing a value".($reason === '' ? '' : ": {$reason}"));
    }
}

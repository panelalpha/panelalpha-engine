<?php

namespace App\Console\Commands\Concerns;

/**
 * `--env-var KEY=VALUE`, repeatable; `--env` is Laravel's own option and
 * cannot be taken.
 */
trait ParsesEnvVarOptions
{
    /**
     * Null when one is malformed: a mistyped override is worth an error rather
     * than a deploy without the variable it was supposed to carry.
     *
     * @return ?array<string, string>
     */
    protected function envVarOptions(): ?array
    {
        /** @var array<int, string> $pairs */
        $pairs = (array) $this->option('env-var');
        $vars = [];
        foreach ($pairs as $pair) {
            $parsed = self::envVarPair($pair);
            if ($parsed === null) {
                $this->error("--env-var expects KEY=VALUE, got: {$pair}");
                return null;
            }
            $vars[$parsed[0]] = $parsed[1];
        }

        return $vars;
    }

    /** @return ?array{0: string, 1: string} */
    private static function envVarPair(string $pair): ?array
    {
        if (!str_contains($pair, '=')) {
            return null;
        }
        [$key, $value] = explode('=', $pair, 2);
        $key = trim($key);

        return $key === '' ? null : [$key, $value];
    }
}

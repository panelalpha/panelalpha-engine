<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

/**
 * Whether the engine has to generate a secret_key_base. Only `config/master.key`
 * settles it: `credentials.yml.enc` is ciphertext without that key.
 * RAILS_MASTER_KEY or SECRET_KEY_BASE in the environment counts too.
 */
final class RailsSecret
{
    private const MASTER_KEY = 'config/master.key';

    /** @var list<string> */
    private const ENVIRONMENT_KEYS = ['RAILS_MASTER_KEY', 'SECRET_KEY_BASE'];

    /**
     * @param  array<string, mixed>  $env
     */
    public static function mustBeGenerated(string $projectDir, array $env = []): bool
    {
        return ! self::providedByEnvironment($env)
            && ! is_file(rtrim($projectDir, '/').'/'.self::MASTER_KEY);
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private static function providedByEnvironment(array $env): bool
    {
        foreach (self::ENVIRONMENT_KEYS as $key) {
            if (trim((string) ($env[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}

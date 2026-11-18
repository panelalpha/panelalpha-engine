<?php

namespace App\Lib\Deploy\Compose;

/**
 * The files a compose file's top-level `secrets:` read that the repository leaves out (a gitignored
 * `config/postgres_password.txt`), and the value the engine writes in each: generated from the account's
 * seed, so the database reading it and {@see SecretFileEnvironment} agree.
 *
 * No Laravel dependencies — unit-testable.
 */
final class SecretSourceFiles
{
    /** Leaves `${VAR}` unresolved, which the engine does not guess at. */
    private const INTERPOLATED = '/\$\{?[A-Za-z_]/';

    /**
     * @param  array<string, mixed>  $compose  the run file, its paths relative to the checkout
     * @param  callable(string): bool  $exists  whether a checkout-relative path exists
     * @return array<string, string> checkout-relative path => the secret's name
     */
    public static function missing(array $compose, callable $exists): array
    {
        $secrets = $compose['secrets'] ?? null;
        if (! is_array($secrets)) {
            return [];
        }
        $missing = [];
        foreach ($secrets as $name => $entry) {
            $file = is_array($entry) ? ($entry['file'] ?? null) : null;
            if (is_string($name) && is_string($file) && self::isCheckoutPath($file) && ! $exists(self::relative($file))) {
                $missing[self::relative($file)] ??= $name;
            }
        }

        return $missing;
    }

    /** The value written in a missing secret file and read for it, the same for one account on every deploy. */
    public static function value(string $secret, string $seed): string
    {
        return ComposePlaceholders::requiredSecretValue($secret, $seed);
    }

    /** A relative path inside the checkout, with no interpolation: only those are created. */
    public static function isCheckoutPath(string $file): bool
    {
        $relative = self::relative($file);

        return $relative !== '' && ! str_starts_with(trim($file), '/') && ! str_starts_with(trim($file), '~')
            && ! in_array('..', explode('/', $relative), true) && preg_match(self::INTERPOLATED, $file) !== 1;
    }

    /** `./config/x.txt` as `config/x.txt`. */
    public static function relative(string $file): string
    {
        return (string) preg_replace('#^(\./)+#', '', trim($file));
    }
}

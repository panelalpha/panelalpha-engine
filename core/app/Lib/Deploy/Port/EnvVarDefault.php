<?php

namespace App\Lib\Deploy\Port;

/**
 * Shell-style variables in a compose value as Compose resolves them unset: `${VAR:-d}` and `${VAR-d}` give d;
 * `${VAR}`, `$VAR` and `${VAR:?err}` give nothing, which the caller's `> 0` port guard then drops.
 */
final class EnvVarDefault
{
    private const NAME = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * `*`, not `+`, on the default: `${HTTPS_BIND:-}` means "no bind address unless set", and requiring a
     * character split Mailcow's `${HTTPS_BIND:-}:${HTTPS_PORT:-443}:443` into four parts and dropped it.
     *
     * @var array<string, string> pattern => replacement
     */
    private const RULES = [
        '/\$\{('.self::NAME.'):-([^}]*)\}/' => '$2',
        '/\$\{('.self::NAME.')-([^}]*)\}/' => '$2',
        '/\$\{('.self::NAME.'):?\?[^}]*\}/' => '',
        '/\$\{('.self::NAME.')\}/' => '',
        '/\$('.self::NAME.')/' => '',
    ];

    public static function resolve(string $value): string
    {
        foreach (self::RULES as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }
}

<?php

namespace App\Lib\Deploy\Port;

/**
 * Shell-style variable expressions in a compose value, resolved to what
 * Compose itself would use when nothing sets the variable.
 *
 *     ${VAR:-default}   the default, when unset or empty
 *     ${VAR-default}    the default, when unset
 *     ${VAR} or $VAR    nothing — a port with no default is dropped by the
 *                       caller's `> 0` guard
 *     ${VAR:?err}       nothing — required, and there is no default to read
 */
final class EnvVarDefault
{
    private const NAME = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * `*` and not `+` on the default: `${HTTPS_BIND:-}` is how a compose file
     * says "no bind address unless you set one", and requiring a character
     * left it unresolved -- so `${HTTPS_BIND:-}:${HTTPS_PORT:-443}:443` split
     * into four parts instead of three and the whole mapping was dropped.
     * Mailcow publishes 80 and 443 that way and the engine saw neither.
     *
     * @var array<string, string> pattern => replacement
     */
    private const RULES = [
        '/\$\{(' . self::NAME . '):-([^}]*)\}/' => '$2',
        '/\$\{(' . self::NAME . ')-([^}]*)\}/' => '$2',
        '/\$\{(' . self::NAME . '):?\?[^}]*\}/' => '',
        '/\$\{(' . self::NAME . ')\}/' => '',
        '/\$(' . self::NAME . ')/' => '',
    ];

    public static function resolve(string $value): string
    {
        foreach (self::RULES as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }
}

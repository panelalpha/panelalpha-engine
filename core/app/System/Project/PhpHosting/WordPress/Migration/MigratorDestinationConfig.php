<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use RuntimeException;

/**
 * The destination plugin's config.php: destination mode and a token that
 * belongs to one migration. The release zip's own token is never used, so two
 * migrations never accept each other's chunks. Disk I/O for account-owned
 * paths is done by the caller (AccountFileCopy).
 */
final class MigratorDestinationConfig
{
    private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

    /** 64 hex characters, the only token format the plugin accepts. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** The migration's own token when it already holds a valid one (a resumed run), else a fresh one. */
    public static function tokenFor(?string $current): string
    {
        return is_string($current) && preg_match(self::TOKEN_PATTERN, $current) === 1 ? $current : self::newToken();
    }

    /** Destination-mode config.php body carrying the given token. */
    public static function destinationBody(string $token): string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new RuntimeException('A migrator token must be 64 lowercase hex characters.');
        }

        return "<?php\n\nreturn [\n"
            .'    \'token\' => '.var_export($token, true).",\n"
            ."    'mode' => 'destination', // source / destination\n"
            ."    'showButtons' => true,\n"
            ."];\n";
    }
}

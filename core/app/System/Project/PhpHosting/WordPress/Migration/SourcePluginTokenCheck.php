<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;
use RuntimeException;

/**
 * Whether the source plugin refuses its transfer routes without the token. A
 * release from {@see WordpressMigratorZip::AUTHENTICATED_VERSION} on must, and
 * the import stops when it does not. An older one cannot: the import goes on
 * with a warning, and the plugin is removed (wp_admin), or the user is told to
 * remove it (manual_plugin), as soon as the import ends, fails or is cancelled.
 */
final class SourcePluginTokenCheck
{
    public const WARNING = 'plugin_unauthenticated';

    public static function run(WordpressMigration $migration, SourceWordpressClient $client, bool $releaseRequiresToken): void
    {
        if (! $client->answersWithoutToken()) {
            $migration->removeWarning(self::WARNING);

            return;
        }
        if ($releaseRequiresToken) {
            throw new RuntimeException(
                'The wordpress-migrator plugin on the source site answers its transfer routes without a token, '
                .'although its release ('.WordpressMigratorZip::AUTHENTICATED_VERSION.' or later) must refuse them',
            );
        }

        $migration->addWarning(self::WARNING, self::warning($migration));
    }

    private static function warning(WordpressMigration $migration): string
    {
        $exposure = 'The wordpress-migrator plugin on the source site is older than '
            .WordpressMigratorZip::AUTHENTICATED_VERSION.' and answers its transfer routes without a token, '
            .'so anyone can copy the site with it while it is installed. ';

        return $exposure.($migration->usesManualPlugin()
            ? 'Remove it from the source site as soon as the import ends, fails or is cancelled.'
            : 'The Engine removes it as soon as the import ends, fails or is cancelled.');
    }
}

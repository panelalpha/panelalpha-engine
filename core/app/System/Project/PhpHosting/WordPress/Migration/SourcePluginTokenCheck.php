<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;
use RuntimeException;

/**
 * Whether the source plugin refuses its routes without the token: from {@see WordpressMigratorZip::AUTHENTICATED_VERSION} it
 * must or the import stops; an older one cannot, so the import warns and the plugin goes (or the user is told) once it ends.
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

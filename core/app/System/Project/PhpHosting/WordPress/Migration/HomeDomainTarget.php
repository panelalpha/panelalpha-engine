<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Exceptions\ProblemException;
use App\Models\WordpressMigration;
use RuntimeException;

/**
 * A new project whose domain was left to the source follows the real home resolve_source stored where it differs from
 * the URL given (a `www.` home, a login page, an IP); a home whose domain is taken fails rather than lands elsewhere.
 */
final class HomeDomainTarget
{
    public function __construct(
        private ?MigrationTargetResolver $resolver = null,
    ) {
        $this->resolver ??= new MigrationTargetResolver;
    }

    public function follow(WordpressMigration $migration): void
    {
        if (! self::followsHome($migration)) {
            return;
        }

        if (MigrationTargetResolver::defaultDomain($migration->source_url) === $migration->target_label) {
            return;
        }

        $migration->storeTargetDomain($this->homeDomain($migration));
    }

    private static function followsHome(WordpressMigration $migration): bool
    {
        return $migration->created_project && ! $migration->domain_given;
    }

    private function homeDomain(WordpressMigration $migration): ?string
    {
        try {
            return $this->resolver->resolve($migration->source_url)->domain;
        } catch (ProblemException $e) {
            throw new RuntimeException(
                "The source's home is {$migration->source_url}, not the URL the import was started with, "
                    .'and its domain cannot be used: '.$e->getMessage()
                    .' Start the import again with `domain`.',
                0,
                $e,
            );
        }
    }
}

<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Exceptions\ProblemException;
use App\Models\WordpressMigration;
use RuntimeException;

/**
 * Once resolve_source has stored the site's real home, a new project whose
 * domain the caller left to the source follows that home where it differs
 * from the URL the start was given: a `www.` home, a site found through its
 * login page, a named site given by its IP. A home whose domain is taken
 * fails the import rather than landing it somewhere else.
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

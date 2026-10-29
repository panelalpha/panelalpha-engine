<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\HomeDomainTarget;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;

/**
 * First step: find the source WordPress (wp_admin logs in, manual_plugin finds
 * the plugin and checks its token) and store its home, login URL and REST
 * mode, before anything is created on the destination. A domain left to the
 * source then follows that home.
 */
final class ResolveSourceStep implements MigrationStep
{
    public function __construct(
        private SourceConnection $source = new SourceConnection,
        private HomeDomainTarget $homeDomain = new HomeDomainTarget,
    ) {}

    public function name(): string
    {
        return WordpressMigration::STEP_RESOLVE_SOURCE;
    }

    public function run(WordpressMigration $migration): void
    {
        $this->source->open($migration);
        $this->homeDomain->follow($migration);
        $migration->markStepCompleted($this->name());
    }
}

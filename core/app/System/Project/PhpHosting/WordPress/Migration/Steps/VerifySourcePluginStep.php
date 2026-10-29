<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourcePluginTokenCheck;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;

/**
 * manual_plugin in place of installing the source plugin: the plugin the user
 * installed must still answer, and still accept the token, before remote-sync.
 * One that answers its transfer routes without the token is recorded as a
 * warning ({@see SourcePluginTokenCheck}): its release is not known here.
 */
final class VerifySourcePluginStep implements MigrationStep
{
    public function __construct(
        private SourceConnection $source = new SourceConnection,
    ) {}

    public function name(): string
    {
        return WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN;
    }

    public function run(WordpressMigration $migration): void
    {
        $client = $this->source->open($migration);
        $client->assertPluginAcceptsToken((string) SourceAccess::of($migration)->sourceToken);
        SourcePluginTokenCheck::run($migration, $client, false);

        $migration->markStepCompleted($this->name());
        WordpressMigratorZip::discard($migration->id);
    }
}

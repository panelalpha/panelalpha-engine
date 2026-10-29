<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;

/**
 * Step 10: clear encrypted password and migrator token, then mark completed.
 * A failed earlier step must not run this — secrets stay for resume.
 */
class ClearSecretsStep implements MigrationStep
{
    public function name(): string
    {
        return WordpressMigration::STEP_CLEAR_SECRETS;
    }

    public function run(WordpressMigration $migration): void
    {
        $migration->clearSecrets();
        $migration->markStepCompleted($this->name());
    }
}

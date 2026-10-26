<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;

interface MigrationStep
{
    public function name(): string;

    public function run(WordpressMigration $migration): void;
}

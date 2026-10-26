<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * A step whose run does a bounded share of its work and may return before
 * the step is completed; the next run continues where it stopped.
 */
interface BatchedStep extends MigrationStep {}

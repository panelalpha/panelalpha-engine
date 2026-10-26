<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * What one bounded run of a migration left behind.
 */
enum MigrationSlice
{
    /** More work remains: queue the next run. */
    case More;

    /** Completed, failed, cancelled, or nothing to do. */
    case Done;

    /** Another run holds the record. */
    case Busy;
}

<?php

namespace App\Lib\Project;

/** What a due backup schedule came to in one {@see ScheduledBackups::runDue()} pass. */
enum ScheduledBackupOutcome
{
    /** A backup was queued. */
    case Started;

    /** The project is busy; the next pass tries again. */
    case Waiting;

    /** The project stayed busy for an hour; this occurrence is given up and the schedule moved to its next. */
    case Skipped;

    /** Its project or container is gone; the schedule is turned off. */
    case Disabled;
}

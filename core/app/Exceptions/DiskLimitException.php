<?php

namespace App\Exceptions;

/** A deploy step was stopped because the host or the account ran out of its disk allowance. See StepWatchdog. */
class DiskLimitException extends \RuntimeException
{
}

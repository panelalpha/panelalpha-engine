<?php

namespace App\Exceptions;

/** A deploy step printed nothing for too long and was killed. See StepWatchdog. */
class BuildStalledException extends \RuntimeException {}

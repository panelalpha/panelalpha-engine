<?php

namespace App\Exceptions;

/** A host compile that failed after its dependency install had finished: the failure is the build's. See HostCompile. */
class BuildFailedAfterInstallException extends \Exception {}

<?php

namespace App\Lib\Deploy\Health;

use RuntimeException;

/** A shipped check file the engine cannot read or does not understand. */
final class CheckException extends RuntimeException {}

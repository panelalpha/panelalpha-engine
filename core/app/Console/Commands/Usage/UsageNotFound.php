<?php

namespace App\Console\Commands\Usage;

use RuntimeException;

/** The project or domain a usage command names does not exist. */
final class UsageNotFound extends RuntimeException
{
}

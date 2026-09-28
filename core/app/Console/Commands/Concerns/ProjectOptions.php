<?php

namespace App\Console\Commands\Concerns;

/**
 * The option fragment every fleet-wide command's signature ends with, so the
 * three options stay spelled the same everywhere.
 *
 * A class rather than a constant on {@see SelectsProjects}: PHP will not let a
 * trait constant be read through the trait's own name, which is what a
 * `$signature` initializer has to do.
 */
final class ProjectOptions
{
    public const SIGNATURE =
        ' {--project= : Project username} {--username= : Deprecated alias for --project} {--all}';
}

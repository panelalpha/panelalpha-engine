<?php

namespace App\Lib\Deploy\Inspect;

/**
 * The repository could not be read from its file list, so inspection clones it instead; a create, which
 * never clones to inspect, names it in the deploy log as why it did not inspect.
 */
final class TreeUnavailable extends \RuntimeException {}

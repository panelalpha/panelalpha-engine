<?php

namespace App\System\Project\PhpHosting;

/**
 * The account's entrypoint runner has no script for this PHP version: no domain
 * uses it, so there is no handler to restart. Distinct from a failed restart so
 * each caller decides whether that is expected.
 */
final class PhpHandlerNotRunning extends \RuntimeException
{
}

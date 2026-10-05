<?php

namespace App\System\Project\PhpHosting;

/**
 * The account has no service for this PHP version: no domain uses it, so
 * there is no handler to restart. Distinct from a failed restart so
 * each caller decides whether that is expected.
 */
final class PhpHandlerNotRunning extends \RuntimeException
{
}

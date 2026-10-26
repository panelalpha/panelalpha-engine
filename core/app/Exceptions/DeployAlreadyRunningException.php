<?php

namespace App\Exceptions;

/**
 * A second deploy was requested while one already holds the account's deploy
 * lock. Domain-level on purpose: the same code path runs from HTTP, the queue
 * and the CLI, so the transport decides what a conflict looks like.
 */
class DeployAlreadyRunningException extends \Exception {}

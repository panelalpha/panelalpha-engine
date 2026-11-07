<?php

namespace App\Lib\Domains;

/**
 * A domain the caller named by hand (a `*.panelalpha.online` label someone holds) cannot be had.
 * A generated name never throws: the ladder drops a rung and says why in `fallback_reason`.
 */
class DomainAllocationException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'domain_unavailable')
    {
        parent::__construct($message);
    }
}

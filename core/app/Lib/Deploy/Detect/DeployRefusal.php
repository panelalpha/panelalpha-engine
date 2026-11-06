<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Findings\Finding;
use App\Lib\Findings\FindingException;
use InvalidArgumentException;

/**
 * A deploy refused before anything is provisioned, with the code a client branches on. An InvalidArgumentException, so
 * every caller that catches one still does; `$code` is PHP's int, hence `$refusal`.
 */
final class DeployRefusal extends InvalidArgumentException implements FindingException
{
    /**
     * @param  array<string, mixed>  $facts
     */
    public function __construct(public readonly string $refusal, string $message, public readonly array $facts = [])
    {
        parent::__construct($message);
    }

    public function finding(): Finding
    {
        return new Finding($this->refusal, $this->getMessage(), $this->facts);
    }

    /** An exception that knows its finding says it; anything else is filed under $fallback. */
    public static function findingOf(\Throwable $e, string $fallback): Finding
    {
        return $e instanceof FindingException ? $e->finding() : new Finding($fallback, $e->getMessage());
    }
}

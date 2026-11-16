<?php

namespace App\System\Project\Dind;

use App\System\Privileged;

/** A CPU limit the account's compose file carries, which its running container takes only once it is recreated. */
final class CpuLimitDeferred extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('The CPU limit is saved, but the running account keeps its current one until its container '
            ."is next recreated: {$reason}.");
    }

    /** A root broker from before live CPU limits answers that it has no such action. */
    public static function refusedByTheRootBroker(string $said): self
    {
        $reason = Privileged::refusalReason($said);

        return self::because($reason === '' ? 'the root broker refused it' : "the root broker refused it ({$reason})");
    }
}

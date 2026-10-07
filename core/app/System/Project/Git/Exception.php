<?php

namespace App\System\Project\Git;

class Exception extends \RuntimeException
{
    /**
     * `$problemCode` names a 422 for problems[], as the request-level refusals do;
     * `$taskId` the queued or running deploy a 409 waits for.
     */
    public function __construct(string $message, public int $httpStatus = 400, public ?string $problemCode = null, public ?int $taskId = null)
    {
        parent::__construct($message);
    }
}

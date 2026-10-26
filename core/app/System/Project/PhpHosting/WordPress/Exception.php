<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * A named WordPress operation failed. Controllers map this to an HTTP error
 * whose body carries the WP-CLI output; it is never a 200 with exit_code.
 */
class Exception extends \RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

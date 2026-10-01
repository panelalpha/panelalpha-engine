<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A lookup by name found nothing. The message names what was missing; the CLI
 * prints it as is, and HTTP keeps answering a plain 404 "Not found".
 */
class NotFoundException extends ModelNotFoundException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A lookup by name found nothing. The message names what was missing; the CLI
 * prints it as is. HTTP answers 404 "<Model> not found" when the model is
 * given, a plain "Not found" when it is not.
 */
class NotFoundException extends ModelNotFoundException
{
    /** @param class-string|null $model kept without setModel(), which would overwrite the message */
    public function __construct(string $message, ?string $model = null)
    {
        parent::__construct($message);
        $this->model = $model;
    }
}

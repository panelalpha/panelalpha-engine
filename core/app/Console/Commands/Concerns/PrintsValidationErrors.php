<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Validation\ValidationException;

trait PrintsValidationErrors
{
    // Every message on its own line; a command has no form field to point at.
    protected function failValidation(ValidationException $e): int
    {
        foreach (collect($e->errors())->flatten() as $message) {
            $this->error((string) $message);
        }

        return self::FAILURE;
    }
}

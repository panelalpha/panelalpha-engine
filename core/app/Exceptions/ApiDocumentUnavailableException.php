<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/** The OpenAPI document could not be generated; nothing partial is served in its place. */
class ApiDocumentUnavailableException extends RuntimeException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct('The API document could not be generated: '.$reason, 0, $previous);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], 500);
    }
}

<?php

namespace App\Lib\Apis\PanelAlpha;

class PanelAlphaException extends \RuntimeException
{
    public function __construct(string $message = '', private readonly ?int $responseStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** The HTTP status Connect answered with, when the failure was one. */
    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }
}

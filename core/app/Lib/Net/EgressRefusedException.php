<?php

namespace App\Lib\Net;

use InvalidArgumentException;

/**
 * A URL the engine must not fetch (a scheme it does not speak, or an address outside the egress policy), with
 * the field to report it under. An InvalidArgumentException so the guard's existing callers keep catching it.
 */
final class EgressRefusedException extends InvalidArgumentException
{
    /** The stable problem code every egress refusal is reported with. */
    public const CODE = 'host_refused';

    public function __construct(
        string $message,
        public readonly string $field = 'url',
    ) {
        parent::__construct($message);
    }

    /** @return array{field: string, code: string, message: string} */
    public function problem(): array
    {
        return ['field' => $this->field, 'code' => self::CODE, 'message' => $this->getMessage()];
    }
}

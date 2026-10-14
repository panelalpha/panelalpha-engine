<?php

namespace App\Lib\Net;

use InvalidArgumentException;

/**
 * A URL the engine must not fetch: a scheme it does not speak, or a host that
 * resolves to an address outside the egress policy.
 *
 * Extends InvalidArgumentException so the guard's existing callers and tests
 * keep catching it, and carries the field a validation boundary should report
 * the problem under.
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

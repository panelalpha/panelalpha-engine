<?php

namespace App\System\Privileged;

/** A value refused for a root command. Nothing ran. */
final class InvalidArgument extends \InvalidArgumentException
{
    public static function of(string $kind, string $value): self
    {
        return new self(sprintf('Refusing %s %s for a root command', $kind, json_encode($value, JSON_UNESCAPED_SLASHES)));
    }
}

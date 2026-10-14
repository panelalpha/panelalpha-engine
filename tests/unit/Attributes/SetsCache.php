<?php

namespace Tests\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class SetsCache
{
    public function __construct(
        public readonly string $key,
    ) {
    }
}

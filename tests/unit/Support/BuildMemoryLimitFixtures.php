<?php

namespace Tests\Support;

trait BuildMemoryLimitFixtures
{
    private function memoryFlag(array $build): ?string
    {
        return $build['memory'];
    }
}

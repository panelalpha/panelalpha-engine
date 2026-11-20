<?php

namespace Tests\Support;

trait AppHealthFixtures
{
    private static function fingerprint(int $code, string $hash, bool $default404 = false): array
    {
        return ['code' => $code, 'hash' => $hash, 'default404' => $default404];
    }
}

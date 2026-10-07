<?php

namespace App\Console\Commands\Concerns;

trait PrintsPhpSettings
{
    /**
     * @param array<array-key, mixed> $settings
     */
    protected function printDirectiveMap(array $settings): void
    {
        $map = [];
        foreach ($settings as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $map[$key] = $value;
            }
        }

        $this->line(json_encode($map, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
    }
}

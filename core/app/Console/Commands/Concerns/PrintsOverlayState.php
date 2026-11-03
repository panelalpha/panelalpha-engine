<?php

namespace App\Console\Commands\Concerns;

trait PrintsOverlayState
{
    /**
     * @param  array<string, mixed>  $state
     */
    private function printOverlayState(array $state): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->table(['Setting', 'Value'], $this->overlayRows($state));

        return 0;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{string, string}>
     */
    private function overlayRows(array $state, string $prefix = ''): array
    {
        $rows = [];
        foreach ($state as $key => $value) {
            if (is_array($value)) {
                $rows = [...$rows, ...$this->overlayRows($value, "{$prefix}{$key}.")];

                continue;
            }
            $rows[] = [$prefix.$key, match (true) {
                $value === null => 'none',
                is_bool($value) => $value ? 'yes' : 'no',
                default => (string) $value,
            }];
        }

        return $rows;
    }
}

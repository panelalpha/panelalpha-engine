<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;

/**
 * The run's progress as the status answers it: the current step and its
 * place in the sequence, and one percent over the whole run, weighted
 * setup 0-10, database 10-40, files 40-95 and finish 95-100.
 */
final class MigrationProgress
{
    private const BANDS = [
        'setup' => [0.0, 10.0],
        'database' => [10.0, 40.0],
        'files' => [40.0, 95.0],
        'finish' => [95.0, 100.0],
    ];

    private const TRANSFER_STEPS = [
        WordpressMigration::STEP_MIGRATE_DATABASE => 'database',
        WordpressMigration::STEP_MIGRATE_FILES => 'files',
    ];

    private const MESSAGES = [
        WordpressMigration::STEP_RESOLVE_SOURCE => 'Finding the source site',
        WordpressMigration::STEP_CREATE_PROJECT => 'Creating the project',
        WordpressMigration::STEP_INSTALL_WORDPRESS => 'Installing WordPress',
        WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN => 'Installing the migrator plugin on the new site',
        WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN => 'Installing the migrator plugin on the source site',
        WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN => 'Checking the migrator plugin on the source site',
        WordpressMigration::STEP_REMOTE_SYNC => 'Preparing the transfer',
        WordpressMigration::STEP_MIGRATE_DATABASE => 'Copying the database',
        WordpressMigration::STEP_MIGRATE_FILES => 'Copying the files',
        WordpressMigration::STEP_FINALIZE => 'Finishing the transfer',
        WordpressMigration::STEP_REMOVE_PLUGINS => 'Removing the migrator plugins',
        WordpressMigration::STEP_CLEAR_SECRETS => 'Clearing the stored credentials',
    ];

    public static function stepMessage(string $step): string
    {
        return self::MESSAGES[$step] ?? $step;
    }

    /**
     * @return array{step: ?string, step_index: int, step_count: int, percent: float, phase_percent: float, item: ?string, message: ?string, updated_at: ?string}
     */
    public static function of(WordpressMigration $migration): array
    {
        $names = array_column($migration->steps ?? [], 'name');
        $current = self::currentStep($migration, $names);
        $stored = self::storedFor($migration, $current);
        [$band, $fraction] = self::position($migration, $names, $current, $stored);

        return [
            'step' => $current,
            'step_index' => $current === null ? 0 : (int) array_search($current, $names, true) + 1,
            'step_count' => count($names),
            'percent' => round(self::BANDS[$band][0] + (self::BANDS[$band][1] - self::BANDS[$band][0]) * $fraction, 1),
            'phase_percent' => round(100 * $fraction, 1),
            'item' => $stored['item'] ?? null,
            'message' => $stored['message'] ?? ($current === null ? null : self::stepMessage($current)),
            'updated_at' => $migration->progress['updated_at'] ?? null,
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private static function currentStep(WordpressMigration $migration, array $names): ?string
    {
        return $migration->nextIncompleteStep()['name'] ?? ($names === [] ? null : $names[array_key_last($names)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function storedFor(WordpressMigration $migration, ?string $current): array
    {
        $stored = $migration->progress;

        return is_array($stored) && ($stored['step'] ?? null) === $current ? $stored : [];
    }

    /**
     * The band the current step is in and how far through that band the run is.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $stored
     * @return array{0: string, 1: float}
     */
    private static function position(WordpressMigration $migration, array $names, ?string $current, array $stored): array
    {
        if ($migration->status === WordpressMigration::STATUS_COMPLETED || $current === null) {
            return ['finish', 1.0];
        }

        $band = self::bandOf($current, $names);
        $inBand = array_values(array_filter($names, static fn (string $name): bool => self::bandOf($name, $names) === $band));
        $done = (int) array_search($current, $inBand, true);
        $within = isset(self::TRANSFER_STEPS[$current]) ? ((float) ($stored['phase_percent'] ?? 0.0)) / 100 : 0.0;

        return [$band, min(1.0, ($done + $within) / max(1, count($inBand)))];
    }

    /**
     * @param  list<string>  $names
     */
    private static function bandOf(string $step, array $names): string
    {
        if (isset(self::TRANSFER_STEPS[$step])) {
            return self::TRANSFER_STEPS[$step];
        }

        $database = array_search(WordpressMigration::STEP_MIGRATE_DATABASE, $names, true);

        return $database !== false && array_search($step, $names, true) < $database ? 'setup' : 'finish';
    }
}

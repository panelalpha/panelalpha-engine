<?php

namespace App\Lib\Helpers;

/**
 * Whether five cron fields describe a schedule crond will accept, and nothing more: a refused schedule
 * is a job that silently never runs, so Vixie extensions (`@daily`, `~`, `H`) are rejected.
 */
final class CronSchedule
{
    /**
     * Each field's bounds and the names it accepts, in cron's own order; Sunday is 0 or 7.
     *
     * @var array<string, array{min: int, max: int, names: array<string, int>}>
     */
    private const FIELDS = [
        'minute' => ['min' => 0, 'max' => 59, 'names' => []],
        'hour' => ['min' => 0, 'max' => 23, 'names' => []],
        'day_of_month' => ['min' => 1, 'max' => 31, 'names' => []],
        'month' => ['min' => 1, 'max' => 12, 'names' => [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ]],
        'day_of_week' => ['min' => 0, 'max' => 7, 'names' => [
            'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
        ]],
    ];

    /**
     * Every problem with this schedule, keyed by field, all at once; empty when there is none.
     *
     * @param  array<string, mixed>  $schedule  keyed by field name
     * @return array<string, list<string>>
     */
    public static function errors(array $schedule): array
    {
        $errors = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $fieldErrors = self::fieldErrors($field, (string) ($schedule[$field] ?? ''));
            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public static function fieldNames(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * One field, a comma-separated list of tokens; whitespace would end the field in the crontab.
     * Messages do not repeat the field name: callers key them by it.
     *
     * @return list<string>
     */
    public static function fieldErrors(string $field, string $value): array
    {
        if (! isset(self::FIELDS[$field])) {
            throw new \InvalidArgumentException("Unknown cron field '{$field}'");
        }

        if ($value === '') {
            return ['Empty value'];
        }

        if (preg_match('/\s/', $value) === 1) {
            return ['Whitespace is not allowed inside a field'];
        }

        $errors = [];
        foreach (explode(',', $value) as $part) {
            if ($part === '') {
                $errors[] = 'Empty list element';

                continue;
            }
            $error = self::tokenError(strtolower($part), $field);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * One token: a star, a star with a step, `1-5`, `mon-fri`, `1-5/2`, `30`, `jan`. The step is checked
     * and stripped before the bare-star check, so a zero step on a star is refused.
     */
    private static function tokenError(string $token, string $field): ?string
    {
        if (str_contains($token, '/')) {
            [$base, $step] = array_pad(explode('/', $token, 2), 2, '');
            if (! ctype_digit($step) || (int) $step <= 0) {
                return "Invalid step value in '{$token}'";
            }
            $token = $base;
        }

        if ($token === '*') {
            return null;
        }

        if (str_contains($token, '-')) {
            return self::rangeError($token, $field);
        }

        $rules = self::FIELDS[$field];

        if (ctype_digit($token)) {
            $value = (int) $token;

            return $value < $rules['min'] || $value > $rules['max']
                ? "Value {$value} out of bounds ({$rules['min']}-{$rules['max']})"
                : null;
        }

        return isset($rules['names'][$token]) ? null : "Invalid token '{$token}'";
    }

    /** `1-5` or `mon-fri`, with both ends in bounds and forwards, as crond reads a range. */
    private static function rangeError(string $token, string $field): ?string
    {
        $rules = self::FIELDS[$field];
        [$from, $to] = array_pad(explode('-', $token, 2), 2, '');

        if ($from === '' || $to === '') {
            return "Invalid range '{$token}'";
        }

        $fromValue = self::numeric($from, $rules['names']);
        if ($fromValue === null) {
            return "Invalid token '{$from}' in range '{$token}'";
        }
        $toValue = self::numeric($to, $rules['names']);
        if ($toValue === null) {
            return "Invalid token '{$to}' in range '{$token}'";
        }

        if ($fromValue < $rules['min'] || $fromValue > $rules['max']
            || $toValue < $rules['min'] || $toValue > $rules['max']
        ) {
            return "Range values out of bounds in '{$token}' "
                ."(allowed {$rules['min']}-{$rules['max']})";
        }

        return $fromValue > $toValue
            ? "Range start greater than end in '{$token}'"
            : null;
    }

    /**
     * A range endpoint as a number, by name or by digits. Null when neither.
     *
     * @param  array<string, int>  $names
     */
    private static function numeric(string $value, array $names): ?int
    {
        if ($names !== [] && isset($names[$value])) {
            return $names[$value];
        }

        return ctype_digit($value) ? (int) $value : null;
    }
}

<?php

namespace App\Lib\Helpers;

/**
 * Whether five cron fields describe a schedule crond will accept.
 *
 * Extracted from the controller that used to hold it as a 140-line closure.
 * Nothing here is about HTTP: the same five fields arrive from the API, the
 * console and the MCP tools, and a schedule crond silently refuses is a cron
 * job that never runs and never says why.
 *
 * Deliberately conservative — it accepts the syntax crond does and nothing
 * else. Vixie extensions this does not implement (`@daily`, `~`, `H`) are
 * rejected rather than passed through hopefully.
 *
 * No Laravel dependencies — unit-testable.
 */
final class CronSchedule
{
    /**
     * Each field's bounds and the names it accepts, in cron's own order.
     *
     * `day_of_week` allows 7 as well as 0: crond takes either for Sunday, and
     * a schedule written by hand is as likely to use one as the other.
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
     * Every problem with this schedule, empty when there is none.
     *
     * All fields are checked rather than stopping at the first: someone
     * fixing a cron expression should see everything wrong with it in one
     * round trip.
     *
     * @param array<string, mixed> $schedule keyed by field name
     * @return list<string>
     */
    public static function errors(array $schedule): array
    {
        $errors = [];
        foreach (array_keys(self::FIELDS) as $field) {
            foreach (self::fieldErrors($field, (string) ($schedule[$field] ?? '')) as $error) {
                $errors[] = $error;
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
     * One field, which is a comma-separated list of tokens.
     *
     * @return list<string>
     */
    private static function fieldErrors(string $field, string $value): array
    {
        if ($value === '') {
            return ["{$field}: empty value"];
        }

        // Written into the crontab as is, where whitespace ends the field:
        // `9 , 17` would be read as three fields, not a list.
        if (preg_match('/\s/', $value) === 1) {
            return ["{$field}: whitespace is not allowed inside a field"];
        }

        $errors = [];
        foreach (explode(',', $value) as $part) {
            if ($part === '') {
                // `1,,5` is a typo, not an empty schedule — say which.
                $errors[] = "{$field}: empty list element";
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
     * One token: a star, a star with a step, `1-5`, `mon-fri`, `1-5/2`,
     * `30`, `jan`.
     */
    private static function tokenError(string $token, string $field): ?string
    {
        // A step narrows whatever precedes it, so it is stripped first and the
        // base validated as though the step were not there. This runs before
        // the bare-star check rather than after: `*` used to short-circuit on
        // a pattern that matched any digits, so `*/0` was accepted and passed
        // to crond, which is precisely the silently-never-runs schedule this
        // class exists to catch.
        if (str_contains($token, '/')) {
            [$base, $step] = array_pad(explode('/', $token, 2), 2, '');
            if (!ctype_digit($step) || (int) $step <= 0) {
                return "{$field}: invalid step value in '{$token}'";
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
                ? "{$field}: value {$value} out of bounds ({$rules['min']}-{$rules['max']})"
                : null;
        }

        return isset($rules['names'][$token]) ? null : "{$field}: invalid token '{$token}'";
    }

    /**
     * `1-5` or `mon-fri`, with both ends in bounds and the right way round.
     */
    private static function rangeError(string $token, string $field): ?string
    {
        $rules = self::FIELDS[$field];
        [$from, $to] = array_pad(explode('-', $token, 2), 2, '');

        if ($from === '' || $to === '') {
            return "{$field}: invalid range '{$token}'";
        }

        $fromValue = self::numeric($from, $rules['names']);
        if ($fromValue === null) {
            return "{$field}: invalid token '{$from}' in range '{$token}'";
        }
        $toValue = self::numeric($to, $rules['names']);
        if ($toValue === null) {
            return "{$field}: invalid token '{$to}' in range '{$token}'";
        }

        if ($fromValue < $rules['min'] || $fromValue > $rules['max']
            || $toValue < $rules['min'] || $toValue > $rules['max']
        ) {
            return "{$field}: range values out of bounds in '{$token}' "
                . "(allowed {$rules['min']}-{$rules['max']})";
        }

        // `fri-mon` is not a weekend: crond reads a range forwards only.
        return $fromValue > $toValue
            ? "{$field}: range start greater than end in '{$token}'"
            : null;
    }

    /**
     * A range endpoint as a number, by name or by digits. Null when neither.
     *
     * @param array<string, int> $names
     */
    private static function numeric(string $value, array $names): ?int
    {
        if ($names !== [] && isset($names[$value])) {
            return $names[$value];
        }

        return ctype_digit($value) ? (int) $value : null;
    }
}

<?php

namespace App\Rules;

use Illuminate\Support\Str;
use Illuminate\Contracts\Validation\Validator;

/**
 * What a failed Laravel rule wanted, said as `expected` (and `examples` where
 * they help), so any 422 tells a caller the value to send rather than only that
 * the one it sent was wrong. Rules it cannot explain (regex, closures) get
 * nothing here; a request names those per field.
 */
final class RuleExpectation
{
    private const ANY_DATE = ['expected' => 'a date', 'examples' => ['2026-10-03', '2026-10-03T12:00:00Z']];

    /** PHP date() letters, as a caller writes the format. */
    private const FORMAT_LETTERS = [
        'Y' => 'YYYY', 'y' => 'YY', 'm' => 'MM', 'n' => 'M', 'd' => 'DD', 'j' => 'D',
        'H' => 'HH', 'G' => 'H', 'h' => 'hh', 'g' => 'h', 'i' => 'mm', 's' => 'ss',
        'v' => 'SSS', 'u' => 'SSSSSS', 'A' => 'AM/PM', 'a' => 'am/pm', 'P' => '±HH:MM', 'O' => '±HHMM',
    ];

    /**
     * One problem per message of a failed validator, with the field, a
     * `<field>_<rule>` code, the message, and the expectation where known.
     *
     * @param (callable(string): ?array<string, mixed>)|null $handWritten a request's own expectation for a field; it wins
     * @param (callable(string): string)|null $reportedField the field as the caller spelled it
     * @return list<array<string, mixed>>
     */
    public static function problems(Validator $validator, ?callable $handWritten = null, ?callable $reportedField = null): array
    {
        $problems = [];
        $failed = $validator->failed();
        $allRules = method_exists($validator, 'getRules') ? $validator->getRules() : [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            $rules = $failed[$field] ?? [];
            $names = array_keys($rules);
            $reported = $reportedField !== null ? $reportedField($field) : $field;

            foreach (array_values($messages) as $i => $message) {
                $rule = (string) ($names[$i] ?? 'invalid');
                // A closure or rule object fails under its class name, which is no code.
                $rule = str_contains($rule, '\\') ? 'invalid' : $rule;

                $expectation = ($handWritten !== null ? $handWritten($field) : null)
                    ?? self::describe($rule, (array) ($rules[$rule] ?? []), (array) ($allRules[$field] ?? []));

                $problems[] = [
                    'field' => $reported,
                    'code' => Str::snake($reported) . '_' . Str::snake($rule),
                    'message' => $message,
                ] + ($expectation ?? []);
            }
        }

        return $problems;
    }

    /**
     * @param list<mixed> $params the failed rule's parameters
     * @param list<mixed> $fieldRules every rule on the field, to tell a number from a string or a list
     * @return ?array{expected: string, examples?: list<string>}
     */
    public static function describe(string $rule, array $params, array $fieldRules = []): ?array
    {
        $params = array_map(static fn ($p): string => (string) $p, array_values($params));
        $kind = self::kind($fieldRules);
        $first = $params[0] ?? '';
        $date = self::dateExpectation($fieldRules);

        return match (Str::snake($rule)) {
            'in' => ['expected' => 'one of: ' . implode(', ', $params), 'examples' => array_slice($params, 0, 5)],
            'not_in' => ['expected' => 'anything except: ' . implode(', ', $params)],
            'required', 'present', 'filled' => $date !== null
                ? ['expected' => $date['expected'] . '; this field is required'] + $date
                : ['expected' => 'a value; this field is required'],
            'required_if', 'required_unless', 'required_with', 'required_with_all', 'required_without', 'required_without_all'
                => $date !== null
                    ? ['expected' => $date['expected'] . '; this field is required here'] + $date
                    : ['expected' => 'a value; this field is required here'],
            'integer' => ['expected' => 'an integer', 'examples' => ['10']],
            'numeric', 'decimal' => ['expected' => 'a number', 'examples' => ['10', '1.5']],
            'boolean' => ['expected' => 'a boolean: true, false, 1 or 0', 'examples' => ['true', 'false']],
            'accepted' => ['expected' => 'true', 'examples' => ['true']],
            'string' => ['expected' => 'a string'],
            'array' => ['expected' => 'an array or object'],
            'list' => ['expected' => 'a list'],
            'json' => ['expected' => 'a JSON string', 'examples' => ['{"key": "value"}']],
            'email' => ['expected' => 'an email address', 'examples' => ['ops@example.com']],
            'url', 'active_url' => ['expected' => 'an absolute URL', 'examples' => ['https://example.com/']],
            'ip' => ['expected' => 'an IPv4 or IPv6 address', 'examples' => ['203.0.113.10', '2001:db8::1']],
            'ipv4' => ['expected' => 'an IPv4 address', 'examples' => ['203.0.113.10']],
            'ipv6' => ['expected' => 'an IPv6 address', 'examples' => ['2001:db8::1']],
            'uuid' => ['expected' => 'a UUID', 'examples' => ['123e4567-e89b-12d3-a456-426614174000']],
            'date' => $date ?? self::ANY_DATE,
            'date_format' => self::formatExpectation($params),
            'alpha' => ['expected' => 'letters only'],
            'alpha_num' => ['expected' => 'letters and digits only'],
            'alpha_dash' => ['expected' => 'letters, digits, dashes and underscores only'],
            'lowercase' => ['expected' => 'lowercase text'],
            'digits' => ['expected' => "exactly {$first} digits"],
            'digits_between' => ['expected' => 'between ' . $first . ' and ' . ($params[1] ?? '') . ' digits'],
            'min' => ['expected' => self::bound($kind, 'at least', $first)],
            'max' => ['expected' => self::bound($kind, 'at most', $first)],
            'size' => ['expected' => self::bound($kind, 'exactly', $first)],
            'between' => ['expected' => self::bound($kind, 'between', $first . ' and ' . ($params[1] ?? ''))],
            'gt' => is_numeric($first) ? ['expected' => self::bound($kind, 'more than', $first)] : null,
            'gte' => is_numeric($first) ? ['expected' => self::bound($kind, 'at least', $first)] : null,
            'lt' => is_numeric($first) ? ['expected' => self::bound($kind, 'less than', $first)] : null,
            'lte' => is_numeric($first) ? ['expected' => self::bound($kind, 'at most', $first)] : null,
            'unique' => ['expected' => 'a value not already in use'],
            default => null,
        };
    }

    /**
     * What a date field takes, from its `date_format` (or `date`) rule, so a
     * `required` failure says the format too rather than only "a value".
     *
     * @param list<mixed> $fieldRules
     * @return ?array{expected: string, examples: list<string>}
     */
    private static function dateExpectation(array $fieldRules): ?array
    {
        $formats = [];
        $isDate = false;
        foreach ($fieldRules as $r) {
            if (!is_string($r)) {
                continue;
            }
            [$name, $args] = array_pad(explode(':', $r, 2), 2, '');
            $name = strtolower($name);
            if ($name === 'date_format') {
                $formats = array_merge($formats, explode(',', $args));
            }
            $isDate = $isDate || $name === 'date';
        }
        if ($formats !== []) {
            return self::formatExpectation($formats);
        }

        return $isDate ? self::ANY_DATE : null;
    }

    /**
     * `Y-m-d` becomes `YYYY-MM-DD`, with an example rendered in that format.
     * A letter with no plain spelling (a weekday name, a Unix timestamp)
     * leaves the PHP format as it is; the example still shows the shape.
     *
     * @param list<string> $formats
     * @return array{expected: string, examples: list<string>}
     */
    private static function formatExpectation(array $formats): array
    {
        $example = new \DateTimeImmutable('2026-10-03 14:30:00', new \DateTimeZone('UTC'));
        $spelled = [];
        $examples = [];
        foreach ($formats as $format) {
            $spelled[] = self::spellFormat($format) ?? $format;
            $examples[] = $example->format($format);
        }

        return ['expected' => 'a date in the format ' . implode(' or ', $spelled), 'examples' => array_values(array_unique($examples))];
    }

    private static function spellFormat(string $format): ?string
    {
        $out = '';
        for ($i = 0, $n = strlen($format); $i < $n; $i++) {
            $c = $format[$i];
            if ($c === '\\') {
                $out .= $format[++$i] ?? '';
            } elseif (isset(self::FORMAT_LETTERS[$c])) {
                $out .= self::FORMAT_LETTERS[$c];
            } elseif (ctype_alpha($c)) {
                return null;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    private static function bound(string $kind, string $how, string $n): string
    {
        return match ($kind) {
            'number' => "a number {$how} {$n}",
            'list' => "{$how} {$n} items",
            'file' => "a file of {$how} {$n} KB",
            default => "{$how} {$n} characters",
        };
    }

    /** @param list<mixed> $fieldRules */
    private static function kind(array $fieldRules): string
    {
        foreach ($fieldRules as $r) {
            $name = is_string($r) ? strtolower(explode(':', $r, 2)[0]) : '';
            if (in_array($name, ['integer', 'numeric', 'decimal'], true)) {
                return 'number';
            }
            if (in_array($name, ['array', 'list'], true)) {
                return 'list';
            }
            if (in_array($name, ['file', 'image', 'mimes', 'mimetypes'], true)) {
                return 'file';
            }
        }

        return 'string';
    }
}

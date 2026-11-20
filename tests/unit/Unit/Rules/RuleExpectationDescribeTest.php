<?php

namespace Tests\Unit\Rules;

use App\Rules\RuleExpectation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

class RuleExpectationDescribeTest extends TestCase
{
    /**
     * @param list<mixed> $params
     * @param list<mixed> $fieldRules
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('rules')]
    public function test_a_failed_rule_says_what_it_expected(string $rule, array $params, array $fieldRules, ?array $expected): void
    {
        $this->assertSame($expected, RuleExpectation::describe($rule, $params, $fieldRules));
    }

    /** @return iterable<string, array{0: string, 1: list<mixed>, 2: list<mixed>, 3: array<string, mixed>|null}> */
    public static function rules(): iterable
    {
        yield 'not_in' => ['not_in', ['a', 'b'], [], ['expected' => 'anything except: a, b']];
        yield 'required on a date' => ['required', [], ['required', 'date'], [
            'expected' => 'a date; this field is required',
            'examples' => ['2026-10-03', '2026-10-03T12:00:00Z'],
        ]];
        yield 'filled' => ['filled', [], ['filled'], ['expected' => 'a value; this field is required']];
        yield 'required_with on a formatted date' => ['required_with', ['other'], ['date_format:Y-m-d'], [
            'expected' => 'a date in the format YYYY-MM-DD; this field is required here',
            'examples' => ['2026-10-03'],
        ]];
        yield 'required_without' => ['required_without', ['other'], [], ['expected' => 'a value; this field is required here']];
        yield 'integer' => ['integer', [], [], ['expected' => 'an integer', 'examples' => ['10']]];
        yield 'decimal' => ['decimal', ['2'], [], ['expected' => 'a number', 'examples' => ['10', '1.5']]];
        yield 'boolean' => ['boolean', [], [], ['expected' => 'a boolean: true, false, 1 or 0', 'examples' => ['true', 'false']]];
        yield 'accepted' => ['accepted', [], [], ['expected' => 'true', 'examples' => ['true']]];
        yield 'string' => ['string', [], [], ['expected' => 'a string']];
        yield 'array' => ['array', [], [], ['expected' => 'an array or object']];
        yield 'list' => ['list', [], [], ['expected' => 'a list']];
        yield 'json' => ['json', [], [], ['expected' => 'a JSON string', 'examples' => ['{"key": "value"}']]];
        yield 'email' => ['email', [], [], ['expected' => 'an email address', 'examples' => ['ops@example.com']]];
        yield 'active_url' => ['active_url', [], [], ['expected' => 'an absolute URL', 'examples' => ['https://example.com/']]];
        yield 'ip' => ['ip', [], [], ['expected' => 'an IPv4 or IPv6 address', 'examples' => [TestNetwork::DOCUMENTATION_3, '2001:db8::1']]];
        yield 'ipv4' => ['ipv4', [], [], ['expected' => 'an IPv4 address', 'examples' => [TestNetwork::DOCUMENTATION_3]]];
        yield 'ipv6' => ['ipv6', [], [], ['expected' => 'an IPv6 address', 'examples' => ['2001:db8::1']]];
        yield 'uuid' => ['uuid', [], [], ['expected' => 'a UUID', 'examples' => ['123e4567-e89b-12d3-a456-426614174000']]];
        yield 'date without a format' => ['date', [], [], ['expected' => 'a date', 'examples' => ['2026-10-03', '2026-10-03T12:00:00Z']]];
        yield 'date_format with an escaped letter and a weekday' => ['date_format', ['Y\TH:i', 'D, d M Y'], [], [
            'expected' => 'a date in the format YYYYTHH:mm or D, d M Y',
            'examples' => ['2026T14:30', 'Sat, 03 Oct 2026'],
        ]];
        yield 'alpha' => ['alpha', [], [], ['expected' => 'letters only']];
        yield 'alpha_num' => ['alpha_num', [], [], ['expected' => 'letters and digits only']];
        yield 'alpha_dash' => ['alpha_dash', [], [], ['expected' => 'letters, digits, dashes and underscores only']];
        yield 'lowercase' => ['lowercase', [], [], ['expected' => 'lowercase text']];
        yield 'digits' => ['digits', ['4'], [], ['expected' => 'exactly 4 digits']];
        yield 'digits_between' => ['digits_between', ['2', '6'], [], ['expected' => 'between 2 and 6 digits']];
        yield 'min of a number' => ['min', ['1'], ['numeric'], ['expected' => 'a number at least 1']];
        yield 'max of a list' => ['max', ['3'], ['array'], ['expected' => 'at most 3 items']];
        yield 'size of a file' => ['size', ['512'], ['file'], ['expected' => 'a file of exactly 512 KB']];
        yield 'between characters' => ['between', ['2', '8'], ['string'], ['expected' => 'between 2 and 8 characters']];
        yield 'gt a number' => ['gt', ['0'], ['integer'], ['expected' => 'a number more than 0']];
        yield 'gt another field' => ['gt', ['other'], ['integer'], null];
        yield 'gte' => ['gte', ['5'], ['mimes:png'], ['expected' => 'a file of at least 5 KB']];
        yield 'lt' => ['lt', ['9'], ['list'], ['expected' => 'less than 9 items']];
        yield 'lte another field' => ['lte', ['other'], [], null];
        yield 'lte' => ['lte', ['9'], [], ['expected' => 'at most 9 characters']];
        yield 'unique' => ['unique', ['users'], [], ['expected' => 'a value not already in use']];
        yield 'regex' => ['regex', ['/x/'], [], null];
        yield 'StudlyCase rule name' => ['RequiredIf', ['a', 'b'], [], ['expected' => 'a value; this field is required here']];
    }
}

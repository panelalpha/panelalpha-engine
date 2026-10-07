<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Requests\DomainStoreRequest;
use App\Mcp\Tools\Api\ApiTool;
use App\Rules\RuleExpectation;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Only three requests said what a field expected; every other 422 was a bare
 * Laravel sentence ("The selected transport is invalid."), and over MCP the
 * project field came back as `username` though the tool calls it `name`.
 */
class ValidationProblemsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);

        Route::post('/api/test-problems/inline', function (HttpRequest $r) {
            $r->validate([
                'transport' => 'required|string|in:http,tcp,udp',
                'port' => 'integer|min:1|max:65535',
                'tags' => 'array|max:3',
                'label' => 'string|max:10',
                'enabled' => 'boolean',
            ]);

            return response()->json([]);
        });
        Route::post('/api/test-problems/domain', fn (DomainStoreRequest $r) => response()->json([]));
        Route::post('/api/test-problems/project/{username}', function (HttpRequest $r) {
            $r->validate(['username' => 'regex:/^[a-z]+$/', 'size' => 'integer']);

            return response()->json([]);
        });
    }

    public function test_an_inline_validation_failure_carries_problems_with_expected(): void
    {
        $response = $this->postJson('/api/test-problems/inline', [
            'transport' => 'bogus',
            'port' => 70000,
            'tags' => ['a', 'b', 'c', 'd'],
            'label' => str_repeat('x', 11),
            'enabled' => 'maybe',
        ])->assertStatus(422);

        // `errors` is exactly what Laravel always sent.
        $response->assertJsonPath('errors.transport.0', 'The selected transport is invalid.');

        $problems = collect($response->json('problems'))->keyBy('field');
        $this->assertSame('transport_in', $problems['transport']['code']);
        $this->assertSame('The selected transport is invalid.', $problems['transport']['message']);
        $this->assertSame('one of: http, tcp, udp', $problems['transport']['expected']);
        $this->assertSame(['http', 'tcp', 'udp'], $problems['transport']['examples']);
        $this->assertSame('port_max', $problems['port']['code']);
        $this->assertSame('a number at most 65535', $problems['port']['expected']);
        $this->assertSame('at most 3 items', $problems['tags']['expected']);
        $this->assertSame('at most 10 characters', $problems['label']['expected']);
        $this->assertSame('enabled_boolean', $problems['enabled']['code']);
    }

    public function test_a_domain_that_is_not_one_says_what_a_domain_looks_like(): void
    {
        $response = $this->postJson('/api/test-problems/domain', ['domain' => 'not a domain', 'type' => 'bogus'])
            ->assertStatus(422);

        $response->assertJsonPath('errors.domain.0', 'The domain format is invalid.');
        $problems = collect($response->json('problems'))->keyBy('field');
        $this->assertSame('domain_regex', $problems['domain']['code']);
        $this->assertSame('a hostname, lowercase, without scheme or path', $problems['domain']['expected']);
        $this->assertSame('one of: addon, subdomain', $problems['type']['expected']);
    }

    public function test_a_rule_nothing_can_explain_still_gets_a_problem_without_expected(): void
    {
        $problem = $this->postJson('/api/test-problems/project/x', ['username' => 'Bad!'])
            ->assertStatus(422)
            ->json('problems.0');

        $this->assertSame(['field' => 'username', 'code' => 'username_regex', 'message' => 'The username format is invalid.'], $problem);
    }

    public function test_mcp_reports_fields_under_the_tool_argument_names(): void
    {
        $tool = new class extends ApiTool {
            protected function method(): string
            {
                return 'POST';
            }

            protected function path(): string
            {
                return '/test-problems/project/{username}';
            }

            protected function pathParams(): array
            {
                return ['username'];
            }

            protected function bodyParams(): array
            {
                return ['username', 'size'];
            }

            protected function argumentNames(): array
            {
                return ['name' => 'username'];
            }
        };

        $result = $tool->handle(new Request(['name' => 'Bad!', 'size' => 'big']));

        $this->assertTrue($result->isError());
        $payload = json_decode((string) $result->content(), true);
        $this->assertSame(422, $payload['status']);
        $this->assertSame(['name', 'size'], array_keys($payload['data']['errors']));
        $problems = collect($payload['data']['problems'])->keyBy('field');
        $this->assertSame('name_regex', $problems['name']['code']);
        $this->assertSame('size_integer', $problems['size']['code']);
    }

    public function test_describe_reads_the_rule_and_the_kind_of_field(): void
    {
        $this->assertSame('a number between 1 and 5', RuleExpectation::describe('Between', [1, 5], ['integer'])['expected']);
        $this->assertSame('between 1 and 5 characters', RuleExpectation::describe('Between', [1, 5], ['string'])['expected']);
        $this->assertSame('an integer', RuleExpectation::describe('Integer', [])['expected']);
        $this->assertSame('a value; this field is required', RuleExpectation::describe('Required', [])['expected']);
        $this->assertNull(RuleExpectation::describe('Regex', ['/x/']));
    }

    public function test_a_missing_date_says_the_format_it_takes(): void
    {
        $validator = Validator::make(['end' => 'yesterday'], [
            'start' => 'required|date_format:Y-m-d',
            'end' => 'required|date_format:Y-m-d|after_or_equal:start',
        ]);
        $validator->fails();
        $problems = collect(RuleExpectation::problems($validator))->keyBy('code');

        $this->assertSame('a date in the format YYYY-MM-DD; this field is required', $problems['start_required']['expected']);
        $this->assertSame(['2026-10-03'], $problems['start_required']['examples']);
        $this->assertSame('a date in the format YYYY-MM-DD', $problems['end_date_format']['expected']);
        $this->assertSame(['2026-10-03'], $problems['end_date_format']['examples']);
    }

    public function test_every_required_rule_names_the_date_format(): void
    {
        foreach (['Required', 'Present', 'Filled'] as $rule) {
            $this->assertSame(
                ['expected' => 'a date in the format YYYY-MM-DD; this field is required', 'examples' => ['2026-10-03']],
                RuleExpectation::describe($rule, [], ['required', 'date_format:Y-m-d']),
                $rule
            );
        }
        $this->assertSame(
            'a date in the format YYYY-MM-DD; this field is required here',
            RuleExpectation::describe('RequiredWith', ['end'], ['required_with:end', 'date_format:Y-m-d'])['expected']
        );
        $this->assertSame('a value; this field is required', RuleExpectation::describe('Required', [], ['required', 'string'])['expected']);
    }

    public function test_a_date_format_is_spelled_for_callers_with_an_example(): void
    {
        $this->assertSame(
            ['expected' => 'a date in the format YYYY-MM-DD HH:mm:ss', 'examples' => ['2026-10-03 14:30:00']],
            RuleExpectation::describe('DateFormat', ['Y-m-d H:i:s'], ['date_format:Y-m-d H:i:s'])
        );
        $this->assertSame(
            'a date in the format YYYY-MM-DDTHH:mm:ss±HH:MM',
            RuleExpectation::describe('DateFormat', ['Y-m-d\\TH:i:sP'])['expected']
        );
        $this->assertSame(
            ['expected' => 'a date in the format DD/MM/YYYY or YYYY-MM-DD', 'examples' => ['03/10/2026', '2026-10-03']],
            RuleExpectation::describe('DateFormat', ['d/m/Y', 'Y-m-d'])
        );
        // A weekday name has no plain spelling: the PHP format stays, the example shows the shape.
        $this->assertSame(
            ['expected' => 'a date in the format D, d M Y', 'examples' => ['Sat, 03 Oct 2026']],
            RuleExpectation::describe('DateFormat', ['D, d M Y'])
        );
    }

    public function test_a_date_rule_answers_with_the_format_when_the_field_has_one(): void
    {
        $this->assertSame('a date in the format YYYY-MM-DD', RuleExpectation::describe('Date', [], ['date', 'date_format:Y-m-d'])['expected']);
        $this->assertSame('a date', RuleExpectation::describe('Date', [], ['date'])['expected']);
        $this->assertSame(
            ['expected' => 'a date; this field is required', 'examples' => ['2026-10-03', '2026-10-03T12:00:00Z']],
            RuleExpectation::describe('Required', [], ['required', 'date'])
        );
    }
}

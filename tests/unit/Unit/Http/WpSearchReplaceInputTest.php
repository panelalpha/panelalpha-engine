<?php

namespace Tests\Unit\Http;

use App\Exceptions\ProblemException;
use App\Http\Requests\Wp\WpSearchReplaceRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * POST /wp/search-replace passes `args` to WP-CLI, which honours its global
 * options (--exec, --require) from any position. Only table names and the
 * search-replace flags the engine allows get through, and neither search
 * nor replacement may start with '-'.
 */
class WpSearchReplaceInputTest extends TestCase
{
    public function test_tables_and_the_allowed_flags_are_accepted(): void
    {
        $body = [
            'search' => 'http://old.test',
            'replacement' => 'https://new.test',
            'args' => ['wp_posts', 'wp_*meta', '--dry-run', '--precise', '--all-tables', '--skip-columns=guid'],
        ];

        $this->assertTrue($this->validator($body)->passes());
    }

    public function test_a_wp_cli_option_off_the_allow_list_is_refused(): void
    {
        $validator = $this->validator(['search' => 'a', 'replacement' => 'b', 'args' => ['--dry-run', '--exec=phpinfo();']]);

        $this->assertFalse($validator->passes());
        $this->assertSame(['args.1'], $validator->errors()->keys());
        $this->assertStringStartsWith('The args.1 must be a table name or one of: --dry-run, --precise', $validator->errors()->first('args.1'));
    }

    public function test_a_search_or_replacement_starting_with_a_dash_is_refused(): void
    {
        $validator = $this->validator(['search' => '--require=/home/alice/x.php', 'replacement' => '-x']);

        $this->assertSame(
            [
                'search' => ["The search must not start with '-': WP-CLI would read it as an option."],
                'replacement' => ["The replacement must not start with '-': WP-CLI would read it as an option."],
            ],
            $validator->errors()->toArray(),
        );
        $this->assertTrue($this->validator(['search' => 'old', 'replacement' => ''])->passes(), 'an empty replacement still removes the search string');
    }

    public function test_a_leading_dash_is_its_own_problem_code(): void
    {
        $request = WpSearchReplaceRequest::create('/', 'POST', ['search' => '--require=/etc/hostname', 'replacement' => '-x']);
        $request->setContainer($this->app);

        try {
            $request->validateResolved();
            $this->fail('Expected the leading dashes to be refused');
        } catch (ProblemException $e) {
            $this->assertSame(
                [['search', 'search_option_like'], ['replacement', 'replacement_option_like']],
                array_map(static fn (array $problem): array => [$problem['field'], $problem['code']], $e->problems),
            );
            $this->assertSame("The search must not start with '-': WP-CLI would read it as an option.", $e->problems[0]['message']);
        }
    }

    /** @param array<string, mixed> $body */
    private function validator(array $body): \Illuminate\Validation\Validator
    {
        $request = new WpSearchReplaceRequest;

        return Validator::make($body, $request->rules(), $request->messages());
    }
}

<?php

namespace Tests\Unit\Support;

use App\Http\Requests\FileRemoveRequest;
use App\Http\Requests\Files\ListRequest;
use App\Http\Requests\Git\GitConnectRequest;
use App\Support\BooleanWordsValidator;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * A query string carries a boolean as text, so the `boolean` rule of every request takes the words `true` and
 * `false` beside 1 and 0, and the validated input holds the boolean the word names.
 */
class BooleanWordsValidatorTest extends TestCase
{
    public function test_every_validator_is_the_one_that_takes_the_words(): void
    {
        $this->assertInstanceOf(BooleanWordsValidator::class, Validator::make([], []));
    }

    public function test_the_words_pass_as_the_booleans_they_name(): void
    {
        $validator = Validator::make(
            ['on' => 'true', 'off' => 'false', 'shouted' => 'TRUE', 'digit' => '1', 'json' => false],
            ['on' => 'boolean', 'off' => 'boolean', 'shouted' => 'boolean', 'digit' => 'boolean', 'json' => 'boolean'],
        );

        $this->assertSame(['on' => true, 'off' => false, 'shouted' => true, 'digit' => '1', 'json' => false], $validator->validated());
    }

    public function test_anything_else_is_still_refused(): void
    {
        foreach (['yes', 'on', 'banana', '2'] as $value) {
            $this->assertTrue(Validator::make(['flag' => $value], ['flag' => 'boolean'])->fails(), $value);
        }
        $this->assertTrue(Validator::make(['flag' => 'true'], ['flag' => 'boolean:strict'])->fails(), 'strict takes only true and false');
    }

    public function test_a_nested_field_holds_the_boolean_too(): void
    {
        $validator = Validator::make(['only' => ['files' => 'false']], ['only.files' => 'boolean']);

        $this->assertSame(['only' => ['files' => false]], $validator->validated());
    }

    /** `required_unless:repair,true` is checked before `repair`'s own rule, and has to see false already. */
    public function test_a_rule_that_depends_on_a_boolean_field_reads_the_word_as_its_boolean(): void
    {
        $refused = Validator::make(['repair' => 'false'], (new GitConnectRequest)->rules());
        $repair = Validator::make(['repair' => 'true'], (new GitConnectRequest)->rules());

        $this->assertSame(['repo_url', 'branch'], array_keys($refused->errors()->messages()));
        $this->assertSame(['repair' => true], $repair->validated());
    }

    public function test_the_requests_whose_query_strings_were_refused_take_the_words(): void
    {
        $list = Validator::make(['path' => '/', 'show_hidden' => 'false'], (new ListRequest)->rules());
        $remove = Validator::make(['path' => 'dir', 'recursive' => 'false'], (new FileRemoveRequest)->rules());

        $this->assertFalse($list->validated()['show_hidden']);
        $this->assertFalse($remove->validated()['recursive']);
    }
}

<?php

namespace Tests\Unit\Http;

use App\Http\Requests\MysqlDatabaseStoreRequest;
use App\Http\Requests\MysqlUserRenameRequest;
use App\Http\Requests\MysqlUserStoreRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The MySQL name grammar accepts hyphens, so the message that states it must say so. */
class MysqlNameRuleTest extends TestCase
{
    #[DataProvider('requestClasses')]
    public function test_hyphens_are_accepted_anywhere(string $request): void
    {
        $this->assertSame([], $this->nameErrors(new $request(), 'shop-db_1'));
        $this->assertSame([], $this->nameErrors(new $request(), '-shop'));
    }

    #[DataProvider('requests')]
    public function test_a_refused_name_is_told_the_grammar_it_was_checked_against(string $request, string $label): void
    {
        $this->assertSame(
            ["{$label} must start with a letter, underscore or hyphen and can only contain letters, numbers, underscores and hyphens."],
            $this->nameErrors(new $request(), 'shop.db'),
        );
        $this->assertSame(
            ["{$label} must start with a letter, underscore or hyphen and can only contain letters, numbers, underscores and hyphens."],
            $this->nameErrors(new $request(), '1shop'),
        );
    }

    public static function requests(): array
    {
        return [
            'database' => [MysqlDatabaseStoreRequest::class, 'Database name'],
            'database user' => [MysqlUserStoreRequest::class, 'MySQL user name'],
            'database user rename' => [MysqlUserRenameRequest::class, 'MySQL user name'],
        ];
    }

    public static function requestClasses(): array
    {
        return array_map(static fn (array $case): array => [$case[0]], self::requests());
    }

    /** @return list<string> */
    private function nameErrors(FormRequest $request, string $name): array
    {
        $request->setContainer($this->app);
        $rules = ['name' => $request->rules()['name']];

        return Validator::make(['name' => $name], $rules, $request->messages())->errors()->get('name');
    }
}

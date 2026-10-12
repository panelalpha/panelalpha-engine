<?php

namespace Tests\Unit\Http;

use App\Http\Requests\DomainStoreRequest;
use App\Http\Requests\DomainUpdateRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Lib\Project\NewProjectRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/** A non-string where a domain field is expected is a 422, not a 500 (engine#800). */
class NonStringDomainFieldsTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function validationErrors(FormRequest $request, array $payload): array
    {
        $base = Request::create('/test', 'POST', $payload);
        $request = $request::createFrom($base);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

        return Validator::make($request->all(), $request->rules())->errors()->toArray();
    }

    /** @return array<string, array{0: FormRequest, 1: array<string, mixed>, 2: string}> */
    public static function nonStringFields(): array
    {
        return [
            'domain as a list' => [new DomainStoreRequest(), ['domain' => ['a.com'], 'type' => 'addon'], 'domain'],
            'parent_domain as an object' => [new DomainStoreRequest(), ['domain' => 'shop.example.com', 'type' => 'sub', 'parent_domain' => ['x' => 1]], 'parent_domain'],
            'aliases as a string' => [new DomainStoreRequest(), ['domain' => 'shop.example.com', 'type' => 'addon', 'aliases' => 'a.com'], 'aliases'],
            'aliases as a list of lists' => [new DomainStoreRequest(), ['domain' => 'shop.example.com', 'type' => 'addon', 'aliases' => [['a.com']]], 'aliases.0'],
            'project update domain' => [new UserUpdateRequest(), ['domain' => ['a.com']], 'domain'],
            'domain update aliases as a string' => [new DomainUpdateRequest(), ['aliases' => 'a.com'], 'aliases'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('nonStringFields')]
    public function test_a_non_string_domain_field_is_a_validation_error_not_a_type_error(FormRequest $request, array $payload, string $field): void
    {
        $errors = $this->validationErrors($request, $payload);

        $this->assertArrayHasKey($field, $errors);
    }

    public function test_a_valid_domain_still_normalises_to_lowercase(): void
    {
        $errors = $this->validationErrors(new DomainStoreRequest(), ['domain' => 'SHOP.Example.COM', 'type' => 'addon']);

        $this->assertArrayNotHasKey('domain', $errors);
    }

    public function test_project_create_normalisation_leaves_a_non_string_domain_for_the_rules(): void
    {
        // normalised() must not cast; the `string` rule is what refuses it.
        [$changes] = NewProjectRules::normalised(['domain' => ['a.com']]);

        $this->assertSame(['a.com'], $changes['domain']);
        $this->assertTrue(
            Validator::make(['domain' => ['a.com']], NewProjectRules::rules('username', null))->fails()
        );
    }
}

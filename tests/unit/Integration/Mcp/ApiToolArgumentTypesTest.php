<?php

namespace Tests\Integration\Mcp;

use App\Mcp\Tools\Api\Projects\ProjectCreateTool;
use App\Mcp\Tools\Api\Projects\ProjectGetTool;
use App\Mcp\Tools\Api\Tasks\TaskGetTool;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An argument is checked against the type the tool's own schema gives it, so a
 * wrong one is refused before anything is dispatched.
 */
class ApiToolArgumentTypesTest extends TestCase
{
    /** @return iterable<string, array{0: class-string, 1: array<string, mixed>, 2: string, 3: string}> */
    public static function wrongTypes(): iterable
    {
        yield 'a string path argument given a list' => [ProjectGetTool::class, ['name' => ['x']], 'name', 'The name must be a string.'];
        yield 'a string path argument given a number' => [ProjectGetTool::class, ['name' => 123], 'name', 'The name must be a string.'];
        yield 'an integer path argument given a list' => [TaskGetTool::class, ['id' => [7]], 'id', 'The id must be an integer.'];
        yield 'an integer path argument given text' => [TaskGetTool::class, ['id' => 'seven'], 'id', 'The id must be an integer.'];
        yield 'an object body argument given text' => [ProjectCreateTool::class, ['name' => 'shop', 'source' => 'https://github.com/acme/shop'], 'source', 'The source must be an array.'];
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('wrongTypes')]
    public function test_a_wrong_typed_argument_is_a_validation_error(string $class, array $arguments, string $field, string $message): void
    {
        try {
            (new $class)->handle(new Request($arguments));
            $this->fail("{$class} accepted an argument of the wrong type");
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->validator->errors()->first($field));
        }
    }

    /**
     * A path parameter stays required and a body field optional.
     */
    public function test_the_rules_keep_the_presence_they_had(): void
    {
        $this->assertSame('required|string', $this->rules(new ProjectGetTool)['name']);
        $this->assertSame('sometimes|nullable|array', $this->rules(new ProjectCreateTool)['source']);
    }

    /** A null is still an omitted argument, and still refused where a value is required. */
    public function test_a_null_is_still_an_omitted_argument(): void
    {
        $rules = $this->rules(new ProjectCreateTool);

        $this->assertFalse(Validator::make(['name' => 'shop', 'source' => null], $rules)->fails());
        $this->assertSame(
            'The name field is required.',
            Validator::make(['name' => null], $this->rules(new ProjectGetTool))->errors()->first('name')
        );
    }

    /** @return array<string, string> */
    private function rules(object $tool): array
    {
        $method = new \ReflectionMethod($tool, 'rules');
        $method->setAccessible(true);

        return $method->invoke($tool);
    }
}

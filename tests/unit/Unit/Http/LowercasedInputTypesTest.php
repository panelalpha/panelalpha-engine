<?php

namespace Tests\Unit\Http;

use App\Http\Requests\ProjectStagingRequest;
use App\Http\Requests\UserCloneRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/** A non-string domain or name used to reach strtolower() and answer 500. */
class LowercasedInputTypesTest extends TestCase
{
    /**
     * @param class-string<FormRequest> $class
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepared(string $class, array $payload): array
    {
        $request = $class::createFrom(Request::create('/x', 'POST', $payload));
        $request->setContainer($this->app);
        (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

        return $request->all();
    }

    public function test_strings_are_lowercased_and_other_types_left_for_the_rules(): void
    {
        foreach ([UserCloneRequest::class, ProjectStagingRequest::class] as $class) {
            $this->assertSame(
                ['domain' => 'shop.example.com', 'new_username' => 'copy1'],
                $this->prepared($class, ['domain' => 'Shop.Example.com', 'new_username' => 'Copy1']),
            );
            $this->assertSame(
                ['domain' => ['a.com'], 'new_username' => ['x' => 1]],
                $this->prepared($class, ['domain' => ['A.com'], 'new_username' => ['x' => 1]]),
                $class
            );
        }
    }
}

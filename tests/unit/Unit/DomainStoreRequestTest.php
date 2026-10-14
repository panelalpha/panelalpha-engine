<?php

namespace Tests\Unit;

use App\Http\Requests\DomainStoreRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Tests\TestCase;

class DomainStoreRequestTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function normalized(array $payload): array
    {
        $base = Request::create('/domains', 'POST', $payload);
        $request = DomainStoreRequest::createFrom($base);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

        return $request->all();
    }

    public function test_documented_subdomain_value_normalizes_to_the_internal_sub_type(): void
    {
        $data = $this->normalized(['domain' => 'shop.example.com', 'type' => 'subdomain']);

        $this->assertSame('sub', $data['type']);
        $this->assertFalse(Validator::make($data, (new DomainStoreRequest())->rules())->fails());
    }

    public function test_internal_sub_value_still_passes_directly(): void
    {
        $data = $this->normalized(['domain' => 'shop.example.com', 'type' => 'sub']);

        $this->assertSame('sub', $data['type']);
        $this->assertFalse(Validator::make($data, (new DomainStoreRequest())->rules())->fails());
    }

    public function test_addon_value_is_untouched(): void
    {
        $data = $this->normalized(['domain' => 'shop.example.com', 'type' => 'addon']);

        $this->assertSame('addon', $data['type']);
        $this->assertFalse(Validator::make($data, (new DomainStoreRequest())->rules())->fails());
    }

    public function test_an_unknown_type_still_fails(): void
    {
        $data = $this->normalized(['domain' => 'shop.example.com', 'type' => 'main']);

        $this->assertTrue(Validator::make($data, (new DomainStoreRequest())->rules())->fails());
    }
}

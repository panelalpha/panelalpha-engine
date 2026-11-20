<?php

namespace Tests\Integration\Http;

use App\Http\Requests\ModsecAuditLogTailRequest;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModsecAuditLogTailRequestTest extends TestCase
{
    public function test_an_omitted_or_empty_limit_asks_for_the_whole_window(): void
    {
        $this->assertNull($this->validatedRequest([])->limit());
        $this->assertNull($this->validatedRequest(['limit' => ''])->limit());
    }

    public function test_a_limit_is_read_as_an_integer(): void
    {
        $this->assertSame(10, $this->validatedRequest(['limit' => '10'])->limit());
    }

    public function test_a_limit_outside_one_to_five_hundred_is_refused(): void
    {
        $this->assertArrayHasKey('limit', $this->errors(['limit' => '0']));
        $this->assertArrayHasKey('limit', $this->errors(['limit' => '501']));
        $this->assertArrayHasKey('limit', $this->errors(['limit' => 'ten']));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function validatedRequest(array $query): ModsecAuditLogTailRequest
    {
        $request = $this->request($query);
        $request->validateResolved();

        return $request;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, array<int, string>>
     */
    private function errors(array $query): array
    {
        try {
            $this->request($query)->validateResolved();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(array $query): ModsecAuditLogTailRequest
    {
        $request = ModsecAuditLogTailRequest::create('/', 'GET', $query);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));

        return $request;
    }
}

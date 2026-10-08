<?php

namespace Tests\Unit;

use App\Http\Controllers\User\AppUserController;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * appManager() aborts with its own status/message (e.g. 403 "only available
 * for dind users") via abort(Response), which throws HttpResponseException --
 * a plain \Exception whose getMessage() is empty. Every app/* endpoint used
 * to catch that as a generic \Exception and flatten it into an empty-message
 * 422, silently discarding the real 403. This is what made app_info's error
 * body look empty on a non-dind project while the same call on a dind
 * project (whose deeper AppManager exception has a real message) read fine.
 */
class AppUserExceptionHandlingTest extends TestCase
{
    private function rethrow(\Exception $e): void
    {
        $controller = new AppUserController();
        (new ReflectionMethod($controller, 'rethrowAppException'))->invoke($controller, $e);
    }

    public function test_an_http_response_exception_passes_through_unchanged(): void
    {
        $response = new JsonResponse(['message' => 'App user management is only available for dind users'], 403);
        $original = new HttpResponseException($response);

        try {
            $this->rethrow($original);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $caught) {
            $this->assertSame($original, $caught);
            $this->assertSame(403, $caught->getResponse()->getStatusCode());
        }
    }

    public function test_a_genuine_app_failure_becomes_a_validation_error_with_its_message(): void
    {
        try {
            $this->rethrow(new \Exception('App management is not supported for this application'));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['App management is not supported for this application'],
                $e->errors()['app'] ?? null
            );
        }
    }
}

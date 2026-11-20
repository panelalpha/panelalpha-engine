<?php

namespace Tests\Integration\Exceptions;

use App\Exceptions\DockerErrorException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Answers the handler gives when there is less to say than usual. */
class HandlerEdgeRenderingTest extends TestCase
{
    public function test_a_daemon_error_with_no_reason_after_its_prefix_keeps_the_whole_message(): void
    {
        $message = "Error response from daemon: \nNo such container: shop";

        $response = $this->app->make(ExceptionHandler::class)->render($this->jsonRequest(), new DockerErrorException($message));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['message' => $message], $response->getData(true));
    }

    public function test_a_validation_failure_without_messages_carries_no_problems(): void
    {
        $response = $this->app->make(ExceptionHandler::class)->render($this->jsonRequest(), ValidationException::withMessages([]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['message' => 'The given data was invalid.', 'errors' => []], $response->getData(true));
    }

    private function jsonRequest(): Request
    {
        return Request::create('/api/projects', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    }
}

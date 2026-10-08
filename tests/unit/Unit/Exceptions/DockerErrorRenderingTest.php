<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\DockerErrorException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Tests\TestCase;

/** A down container is a retryable 503; every other docker error stays 422. */
class DockerErrorRenderingTest extends TestCase
{
    private function statusFor(string $message): int
    {
        $request = Request::create('/api/ip/assign', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);

        return $this->app->make(ExceptionHandler::class)
            ->render($request, new DockerErrorException($message))
            ->getStatusCode();
    }

    public function test_restarting_container_is_503(): void
    {
        $this->assertSame(503, $this->statusFor(
            "Error response from daemon: Container 3f2a9c is restarting, wait until the container is running\n"
        ));
    }

    public function test_stopped_container_is_503(): void
    {
        $this->assertSame(503, $this->statusFor("Error response from daemon: Container 3f2a9c is not running\n"));
        $this->assertSame(503, $this->statusFor('service "sites-http" is not running'));
    }

    public function test_other_docker_errors_stay_422(): void
    {
        $this->assertSame(422, $this->statusFor("Error response from daemon: No such image: foo:latest\n"));
    }

    public function test_the_daemon_prefix_is_stripped_from_the_message(): void
    {
        $request = Request::create('/api/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->app->make(ExceptionHandler::class)->render(
            $request,
            new DockerErrorException("Error response from daemon: Container abc is not running\nmore")
        );

        $this->assertSame(['message' => 'Container abc is not running'], $response->getData(true));
    }

    public function test_an_unrelated_not_running_phrase_is_not_a_container_outage(): void
    {
        $this->assertFalse(DockerErrorException::meansContainerUnavailable('nginx is not running'));
    }
}

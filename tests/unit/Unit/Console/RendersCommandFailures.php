<?php

namespace Tests\Unit\Console;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DockerErrorException;
use App\Exceptions\NotFoundException;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Artisan::call() hands a command's exception to the test, not to the console
 * renderer; this runs one that must fail and returns what artisan would print.
 */
trait RendersCommandFailures
{
    /** @param array<string, mixed> $arguments */
    private function failureOf(string $command, array $arguments): string
    {
        try {
            Artisan::call($command, $arguments);
        } catch (NotFoundException|ValidationException|DeployAlreadyRunningException|DockerErrorException|GitException $e) {
            $this->assertSame('', Artisan::output(), "{$command} printed before failing");
            $output = new BufferedOutput();
            $this->app->make(ExceptionHandler::class)->renderForConsole($output, $e);

            return $output->fetch();
        }

        $this->fail("{$command} did not fail");
    }
}

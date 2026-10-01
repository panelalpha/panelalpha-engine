<?php

namespace App\Console\Commands\Concerns;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DockerErrorException;
use App\Exceptions\ProblemException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Output of the PHP-settings commands. A failure prints the body the API
 * answers with for the same failure, as these commands always have.
 */
trait PrintsPhpSettings
{
    protected function rejectWithBody(string $body): int
    {
        $this->error($body !== '' ? $body : 'Request failed.');

        return 1;
    }

    // Mirrors App\Exceptions\Handler for the exceptions these paths can raise.
    protected function rejectWithException(Throwable $e): int
    {
        report($e);

        if ($e instanceof DeployAlreadyRunningException) {
            return $this->rejectWithBody((string) json_encode(['message' => $e->getMessage()]));
        }
        if ($e instanceof DockerErrorException) {
            $message = Str::betweenFirst($e->getMessage(), "Error response from daemon: ", "\n") ?: $e->getMessage();

            return $this->rejectWithBody((string) json_encode(['message' => $message]));
        }
        if ($e instanceof ValidationException) {
            $data = ['message' => $e->getMessage(), 'errors' => $e->errors()];
            if ($e instanceof ProblemException && $e->problems !== []) {
                $data['problems'] = $e->problems;
            }

            return $this->rejectWithBody((string) json_encode($data));
        }

        $data = config('app.debug') ? [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => (new Collection($e->getTrace()))->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
        ] : [
            'message' => $e instanceof HttpExceptionInterface ? $e->getMessage() : 'Server Error',
        ];

        return $this->rejectWithBody((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // The API failed with a 500 on data it could not encode as JSON.
    protected function requireEncodable(mixed $data): void
    {
        if (json_encode($data) === false) {
            throw new \InvalidArgumentException(json_last_error_msg());
        }
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    protected function printDirectiveMap(array $settings): void
    {
        $map = [];
        foreach ($settings as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $map[$key] = $value;
            }
        }

        $encoded = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
        $this->line($encoded === false ? '{}' : $encoded);
    }
}

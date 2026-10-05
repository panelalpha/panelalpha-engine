<?php

namespace App\Exceptions;

use App\Models\User;
use App\Rules\RuleExpectation;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        // Laravel's own message names the model class (`No query results for
        // model [App\Models\BackupContainer] 5`); say what was missing instead.
        $this->map(ModelNotFoundException::class, function (ModelNotFoundException $e) {
            // The API calls a User a project.
            $model = $e->getModel();
            $what = match (true) {
                $model === null => '',
                $model === User::class => 'Project ',
                default => ucfirst(Str::snake(class_basename($model), ' ')) . ' ',
            };

            return new NotFoundHttpException($what === '' ? 'Not found' : $what . 'not found', $e);
        });

        // A deploy lock conflict is a domain condition, not a server fault:
        // DeployLogger raises it from HTTP, queue and CLI alike, and only the
        // HTTP boundary knows it should read as 409.
        $this->renderable(function (DeployAlreadyRunningException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 409);
        });

        // Rendered, not reported: this decides the response, so it belongs on
        // the same hook as the handler above. Registering it as a *report*
        // callback meant the exception was never logged and the 422 came out of
        // an abort() thrown from inside the reporting pipeline.
        $this->renderable(function (DockerErrorException $e) {
            $message = Str::betweenFirst($e->getMessage(), "Error response from daemon: ", "\n")
                ?: $e->getMessage();

            // A stopped or restarting container is retryable, not a bad request.
            return new JsonResponse(['message' => $message], $e->isContainerUnavailable() ? 503 : 422);
        });
    }

    /**
     * Failures a command's user can act on print as plain lines on stderr;
     * anything else keeps artisan's own rendering.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function renderForConsole($output, Throwable $e)
    {
        if (!self::renderPlainForConsole($output, $e)) {
            parent::renderForConsole($output, $e);
        }
    }

    /**
     * Print a failure the user can act on as plain lines on stderr. Returns
     * false, printing nothing, for any other exception.
     */
    public static function renderPlainForConsole(OutputInterface $output, Throwable $e): bool
    {
        $lines = match (true) {
            $e instanceof ValidationException => collect($e->errors())->flatten()->all(),
            $e instanceof NotFoundException,
            $e instanceof DeployAlreadyRunningException,
            $e instanceof DockerErrorException,
            $e instanceof GitException => [$e->getMessage()],
            default => null,
        };

        if ($lines === null) {
            return false;
        }

        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        foreach ($lines as $line) {
            $stderr->writeln('<error>' . OutputFormatter::escape(trim((string) $line)) . '</error>');
        }

        return true;
    }

    /**
     * The validation response, with `problems` beside `errors` where the
     * thrower had more to say than a sentence.
     *
     * `errors` keeps its shape exactly -- every existing client reads it, and
     * a form has no use for a slug. What is added is the half a program
     * needs: a field, a stable code, and whatever context the failure carried.
     *
     * @return JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        /** @var JsonResponse $response */
        $response = parent::invalidJson($request, $exception);

        // Every 422 carries problems[]: a ProblemException brings its own, any
        // other validation failure gets one per message with what was expected.
        $problems = $exception instanceof ProblemException && $exception->problems !== []
            ? $exception->problems
            : RuleExpectation::problems($exception->validator);
        if ($problems === []) {
            return $response;
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->getData(true);
        $data['problems'] = $problems;

        return $response->setData($data);
    }
}

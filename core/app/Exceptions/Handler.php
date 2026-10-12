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
    protected $levels = [];

    /**
     * Exception types that are not reported: each is a 409 the client is told
     * how to act on (a busy project, a deploy already queued), not a fault.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        ProjectBusyException::class,
        DeployBusyException::class,
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
        $this->map(ModelNotFoundException::class, fn (ModelNotFoundException $e) => self::modelNotFound($e));
        $this->renderConflictsAs409();
        $this->renderable(fn (DockerErrorException $e) => self::dockerError($e));
    }

    /**
     * Name what was missing instead of Laravel's model class; the API calls a User a project.
     */
    private static function modelNotFound(ModelNotFoundException $e): NotFoundHttpException
    {
        $model = $e->getModel();
        $what = match (true) {
            $model === null => '',
            $model === User::class => 'Project ',
            default => ucfirst(Str::snake(class_basename($model), ' ')) . ' ',
        };

        return new NotFoundHttpException($what === '' ? 'Not found' : $what . 'not found', $e);
    }

    /**
     * A deploy lock conflict or a busy project is a domain condition raised from
     * HTTP, queue and CLI alike; only the HTTP boundary knows it reads as 409.
     */
    private function renderConflictsAs409(): void
    {
        $this->renderable(fn (DeployAlreadyRunningException $e) => self::conflict($e->getMessage()));
        $this->renderable(fn (MissingLinuxUserException $e) => self::conflict($e->getMessage()));
        $this->renderable(fn (ProjectBusyException $e) => self::conflict($e->getMessage()));
        $this->renderable(fn (DeployBusyException $e) => self::conflict($e->getMessage(), ['task_id' => $e->task?->id]));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function conflict(string $message, array $extra = []): JsonResponse
    {
        return new JsonResponse(['message' => $message] + $extra, 409);
    }

    /**
     * Rendered rather than reported, since it decides the response: the daemon's
     * own message, 503 for a stopped or restarting container (retryable), else 422.
     */
    private static function dockerError(DockerErrorException $e): JsonResponse
    {
        $message = Str::betweenFirst($e->getMessage(), "Error response from daemon: ", "\n")
            ?: $e->getMessage();

        return new JsonResponse(['message' => $message], $e->isContainerUnavailable() ? 503 : 422);
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
     * A ProblemException brings its own problems; any other failure gets one per
     * message with what was expected.
     *
     * @return JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        /** @var JsonResponse $response */
        $response = parent::invalidJson($request, $exception);

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

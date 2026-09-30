<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            $model = $e->getModel();
            $what = $model === null ? '' : ucfirst(Str::snake(class_basename($model), ' ')) . ' ';

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

        if (!$exception instanceof ProblemException || $exception->problems === []) {
            return $response;
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->getData(true);
        $data['problems'] = $exception->problems;

        return $response->setData($data);
    }
}

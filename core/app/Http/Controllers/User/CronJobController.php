<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\CronJobStoreRequest;
use App\Http\Requests\CronJobUpdateRequest;
use App\Http\Resources\CronJobCollection;
use App\Http\Resources\CronJobResource;
use App\Lib\Helpers\CronSchedule;
use App\Models\User;
use App\System\Project\Dind;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CronJobController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/cron-jobs',
        summary: 'List cron jobs of a project',
        security: [['bearerAuth' => []]],
        tags: ['Cron Jobs'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of cron jobs', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CronJob'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @return CronJobCollection
     */
    public function index($username)
    {
        $user = $this->projectOr404($username);

        return new CronJobCollection($user->project()->cron()->list());
    }

    /**
     * @param array<string, mixed> $req
     * @return bool|list<string> true when valid, the problems otherwise
     */
    private function validateCronSchedule(array $req): bool|array
    {
        $errors = CronSchedule::errors($req);

        return $errors === [] ? true : $errors;
    }

    #[OA\Post(
        path: '/projects/{username}/cron-jobs',
        summary: 'Create a cron job',
        description: 'PHP hosting projects only: a dind account does not run the project crontab, and a dind project answers 422.',
        security: [['bearerAuth' => []]],
        tags: ['Cron Jobs'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['command', 'minute', 'hour', 'day_of_month', 'month', 'day_of_week'],
            properties: [
                new OA\Property(property: 'command', type: 'string', example: '/usr/bin/php /home/johndoe/script.php'),
                new OA\Property(property: 'minute', type: 'string', example: '0'),
                new OA\Property(property: 'hour', type: 'string', example: '*'),
                new OA\Property(property: 'day_of_month', type: 'string', example: '*'),
                new OA\Property(property: 'month', type: 'string', example: '*'),
                new OA\Property(property: 'day_of_week', type: 'string', example: '*'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Cron job created', content: new OA\JsonContent(ref: '#/components/schemas/CronJob')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param CronJobStoreRequest $request
     * @return CronJobResource
     */
    public function store($username, CronJobStoreRequest $request)
    {
        $user = $this->projectOr404($username);
        $this->rejectDind($user);

        /**
         * @var array{
         *   command: string,
         *   minute: string,
         *   hour: string,
         *   day_of_month: string,
         *   month: string,
         *   day_of_week: string,
         * }
         */
        $params = $request->validated();
        $errors = $this->validateCronSchedule($params);
        if (is_array($errors) && !empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $cron = $user->project()->cron();
        $job = $cron->create($params);
        $user->project()->reloadCron();

        return new CronJobResource($job);
    }

    #[OA\Put(
        path: '/projects/{username}/cron-jobs/{hash}',
        summary: 'Update a cron job',
        description: 'PHP hosting projects only: a dind account does not run the project crontab, and a dind project answers 422.',
        security: [['bearerAuth' => []]],
        tags: ['Cron Jobs'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hash', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['command', 'minute', 'hour', 'day_of_month', 'month', 'day_of_week'],
            properties: [
                new OA\Property(property: 'command', type: 'string'),
                new OA\Property(property: 'minute', type: 'string'),
                new OA\Property(property: 'hour', type: 'string'),
                new OA\Property(property: 'day_of_month', type: 'string'),
                new OA\Property(property: 'month', type: 'string'),
                new OA\Property(property: 'day_of_week', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Cron job updated', content: new OA\JsonContent(ref: '#/components/schemas/CronJob')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $hash
     * @param CronJobUpdateRequest $request
     * @return CronJobResource
     */
    public function update($username, $hash, CronJobUpdateRequest $request)
    {
        $user = $this->projectOr404($username);
        $this->rejectDind($user);

        /**
         * @var array{
         *   command: string,
         *   minute: string,
         *   hour: string,
         *   day_of_month: string,
         *   month: string,
         *   day_of_week: string,
         * }
         */
        $params = $request->validated();
        $errors = $this->validateCronSchedule($params);
        if (is_array($errors) && !empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $cron = $user->project()->cron();
        if (!$cron->exists($hash)) {
            abort(new JsonResponse([
                'message' => 'Not found',
            ], 404));
        }
        $job = $cron->update($hash, $params);
        $user->project()->reloadCron();

        return new CronJobResource($job);
    }

    #[OA\Delete(
        path: '/projects/{username}/cron-jobs/{hash}',
        summary: 'Delete a cron job',
        security: [['bearerAuth' => []]],
        tags: ['Cron Jobs'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hash', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cron job deleted', content: new OA\JsonContent(ref: '#/components/schemas/CronJob')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $hash
     * @return CronJobResource
     */
    public function destroy($username, $hash)
    {
        $user = $this->projectOr404($username);

        $cron = $user->project()->cron();
        if (!$cron->exists($hash)) {
            abort(new JsonResponse([
                'message' => 'Not found',
            ], 404));
        }
        $job = $cron->delete($hash);
        $user->project()->reloadCron();

        return new CronJobResource($job);
    }

    /**
     * The account container runs cron but never mounts the project crontab,
     * and has no `php` service to reload: a job saved there would never run.
     */
    private function rejectDind(User $user): void
    {
        if ($user->project()->runtime() instanceof Dind) {
            throw ValidationException::withMessages([
                'command' => 'Cron jobs are not supported for dind projects. '
                    . 'Schedule the work inside the app, for example as a service in its compose file.',
            ]);
        }
    }
}

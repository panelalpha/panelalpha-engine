<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\CronJobStoreRequest;
use App\Http\Requests\CronJobUpdateRequest;
use App\Http\Resources\CronJobCollection;
use App\Http\Resources\CronJobResource;
use App\System\Project\CronRunner;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CronJobController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/cron-jobs',
        summary: 'List cron jobs of a project',
        description: 'On a container project each job names the `service` it runs in; on PHP hosting `service` is null.',
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
     * @param  string  $username
     * @return CronJobCollection
     */
    public function index($username)
    {
        $user = $this->projectOr404($username);

        return new CronJobCollection($user->project()->cron()->list());
    }

    #[OA\Post(
        path: '/projects/{username}/cron-jobs',
        summary: 'Create a cron job',
        description: 'On a container project the job runs as the account user in a running container of `service`, which is required there and refused on PHP hosting. Either kind of project writes each run to ~/'.CronRunner::LOG.' in the account, with the output of the command and its exit status; a container run skipped because the service is not running is written there too.',
        security: [['bearerAuth' => []]],
        tags: ['Cron Jobs'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['command', 'minute', 'hour', 'day_of_month', 'month', 'day_of_week'],
            properties: [
                new OA\Property(property: 'command', type: 'string', example: '/usr/bin/php /home/johndoe/script.php'),
                new OA\Property(property: 'service', type: 'string', example: 'app', description: 'Container projects only, and required there: the Compose service the command runs in.'),
                new OA\Property(property: 'minute', type: 'string', example: '0'),
                new OA\Property(property: 'hour', type: 'string', example: '*'),
                new OA\Property(property: 'day_of_month', type: 'string', example: '*'),
                new OA\Property(property: 'month', type: 'string', example: '*'),
                new OA\Property(property: 'day_of_week', type: 'string', example: '*'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Cron job created', content: new OA\JsonContent(ref: '#/components/schemas/CronJob')),
            new OA\Response(response: 409, description: 'The project has not been deployed yet: problems code `project_not_deployed`', content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
     * @return CronJobResource
     */
    public function store($username, CronJobStoreRequest $request)
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   command: string,
         *   service?: string,
         *   minute: string,
         *   hour: string,
         *   day_of_month: string,
         *   month: string,
         *   day_of_week: string,
         * }
         */
        $params = $request->validated();
        $cron = $user->project()->cron();
        $job = $cron->create($params);
        $user->project()->reloadCron();

        return new CronJobResource($job);
    }

    #[OA\Put(
        path: '/projects/{username}/cron-jobs/{hash}',
        summary: 'Update a cron job',
        description: 'On a container project the job runs as the account user in a running container of `service`, which is required there and refused on PHP hosting. Either kind of project writes each run to ~/'.CronRunner::LOG.' in the account, with the output of the command and its exit status; a container run skipped because the service is not running is written there too. The hash is derived from the job\'s entries, so the job this returns carries a new one: address it by `data.hash` from here on, not by the hash the request used.',
        security: [['bearerAuth' => []]],
        tags: ['Cron Jobs'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hash', in: 'path', required: true, description: 'The job hash, as the list returned it.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['command', 'minute', 'hour', 'day_of_month', 'month', 'day_of_week'],
            properties: [
                new OA\Property(property: 'command', type: 'string'),
                new OA\Property(property: 'service', type: 'string', description: 'Container projects only, and required there: the Compose service the command runs in.'),
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
     * @param  string  $username
     * @param  string  $hash
     * @return CronJobResource
     */
    public function update($username, $hash, CronJobUpdateRequest $request)
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   command: string,
         *   service?: string,
         *   minute: string,
         *   hour: string,
         *   day_of_month: string,
         *   month: string,
         *   day_of_week: string,
         * }
         */
        $params = $request->validated();
        $cron = $user->project()->cron();
        if (! $cron->exists($hash)) {
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
        description: 'The hash is derived from the job\'s entries, so it is the one the list or the last update returned. '
            .'The job stops running and its schedule is not kept anywhere; this cannot be undone. Ask the operator '
            .'to confirm first.',
        security: [['bearerAuth' => []]],
        tags: ['Cron Jobs'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hash', in: 'path', required: true, description: 'The job hash, as the list returned it.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cron job deleted', content: new OA\JsonContent(ref: '#/components/schemas/CronJob')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
     * @param  string  $hash
     * @return CronJobResource
     */
    public function destroy($username, $hash)
    {
        $user = $this->projectOr404($username);

        $cron = $user->project()->cron();
        if (! $cron->exists($hash)) {
            abort(new JsonResponse([
                'message' => 'Not found',
            ], 404));
        }
        $job = $cron->delete($hash);
        $user->project()->reloadCron();

        return new CronJobResource($job);
    }
}

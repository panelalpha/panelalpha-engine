<?php

namespace App\Http\Controllers\User\Mysql;

use App\Exceptions\ProblemException;
use App\Exceptions\ProjectBusyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MysqlDatabaseRenameRequest;
use App\Http\Requests\MysqlDatabaseStoreRequest;
use App\Http\Resources\MysqlDatabaseCollection;
use App\Http\Resources\MysqlDatabaseResource;
use App\Http\Resources\TaskResource;
use App\Jobs\RenameMysqlDatabase;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\MysqlDatabase;
use App\Models\Task;
use App\Models\User;
use App\System;
use App\System\Project\Dind\AppDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class DatabaseController extends Controller
{
    /** MySQL's limit on a database name's length, prefix included. */
    private const MAX_NAME_LENGTH = 64;

    /** @var list<string> */
    private const RESERVED_NAMES = ['information_schema', 'mysql', 'performance_schema', 'sys'];

    #[OA\Get(
        path: '/projects/{username}/mysql/databases',
        summary: 'List MySQL databases of a project',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Databases'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of databases', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MysqlDatabase'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username): MysqlDatabaseCollection
    {
        $user = $this->projectOr404($username);

        $mysql = (new System())->mysql();

        $databases = [];
        foreach ($user->getMysqlDatabases() as $db) {
            $db->size_bytes = $mysql->databases()->getDatabaseSize($db->database);
            $databases[] = $db;
        }

        return new MysqlDatabaseCollection($databases);
    }

    #[OA\Get(
        path: '/projects/{username}/mysql/databases/{dbname}',
        summary: 'Get a MySQL database',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Databases'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbname', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Database details', content: new OA\JsonContent(ref: '#/components/schemas/MysqlDatabase')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbname
     * @return MysqlDatabaseResource
     */
    public function show($username, $dbname)
    {
        $user = $this->projectOr404($username);

        /** @var ?MysqlDatabase */
        $db = $user->mysqlDatabases()->getQuery()->where('database', $user->qualifyMysqlDatabase($dbname))->first();
        if (!$db) {
            abort(new JsonResponse([
                'message' => 'MySQL database not found',
            ], 404));
        }

        return new MysqlDatabaseResource($db);
    }

    #[OA\Post(
        path: '/projects/{username}/mysql/databases',
        summary: 'Create a MySQL database',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Databases'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [new OA\Property(property: 'name', type: 'string', example: 'wp')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Database created', content: new OA\JsonContent(ref: '#/components/schemas/MysqlDatabase')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param MysqlDatabaseStoreRequest $request
     * @return MysqlDatabaseResource
     */
    public function store($username, MysqlDatabaseStoreRequest $request)
    {
        /** @var ?User */
        $user = User::query()->withCount('mysqlDatabases')->where('username', $username)->first();
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'Project not found',
            ], 404));
        }

        $limit = $user->getMysqlDatabasesLimit();
        if ($limit !== null && $limit <= $user->mysql_databases_count) {
            abort(new JsonResponse([
                'message' => "MySQL databases limit of {$limit} reached.",
                'error_type' => 'mysql_databases_limit_reached',
            ], 422));
        }

        /**
         * @var array{
         *   name: string
         * }
         */
        $params = $request->validated();
        $dbname = $params['name'];

        if (!Str::startsWith($dbname, $user->getMysqlPrefix())) {
            $dbname = $user->getMysqlPrefix() . $dbname;
        }

        // Check for reserved MySQL database names
        if (in_array(strtolower($dbname), self::RESERVED_NAMES)) {
            throw ValidationException::withMessages([
                'name' => 'This database name is reserved by MySQL and cannot be used.',
            ]);
        }

        // Validate final database name length (MySQL limit is 64 characters)
        if (strlen($dbname) > self::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages([
                'name' => 'Database name with prefix exceeds 64 characters (MySQL limit). Current length: ' . strlen($dbname) . ' characters.',
            ]);
        }

        if ($user->mysqlDatabases()->getQuery()->where('database', $dbname)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Database with given name already exists.',
            ]);
        }

        $mysql = (new System())->mysql();
        $mysql->databases()->createDatabase($dbname);

        /** @var MysqlDatabase */
        $db = MysqlDatabase::create([
            'user_id' => $user->id,
            'database' => $dbname,
        ]);

        return new MysqlDatabaseResource($db);
    }

    #[OA\Delete(
        path: '/projects/{username}/mysql/databases/{dbname}',
        summary: 'Delete a MySQL database',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Databases'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbname', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Database deleted', content: new OA\JsonContent(ref: '#/components/schemas/MysqlDatabase')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbname
     * @return MysqlDatabaseResource
     */
    public function destroy($username, $dbname)
    {
        $user = $this->projectOr404($username);

        /**
         * @var ?MysqlDatabase $db
         */
        $db = $user->mysqlDatabases()->getQuery()->where('database', $user->qualifyMysqlDatabase($dbname))->first();
        if (!$db) {
            abort(new JsonResponse([
                'message' => 'MySQL database not found',
            ], 404));
        }

        $mysql = (new System())->mysql();
        $mysql->databases()->deleteDatabase($db->database);
        foreach ($user->mysqlUsers as $dbuser) {
            $mysql->privileges()->revokeIfExists($dbuser->user, $db->database);
        }
        $db->delete();

        return new MysqlDatabaseResource($db);
    }

    #[OA\Put(
        path: '/projects/{username}/mysql/databases/{dbname}/rename',
        summary: 'Rename a MySQL database',
        description: 'Renames one of a project\'s MySQL databases: copies its data, moves the grants of every project '
            . 'MySQL user to the new name, updates the stored row and drops the old database. Works for classic and '
            . 'dind projects. Refuses the deploy-managed dind application database (<prefix>app) and a name another '
            . 'database of the project already uses. Returns HTTP 202 with the rename Task; poll `GET /tasks/{id}`.',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Databases'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbname', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [new OA\Property(property: 'name', type: 'string', example: 'newname')],
        )),
        responses: [
            new OA\Response(response: 202, description: 'Rename accepted; `data` is the task to follow', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project or database not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Project busy with another job', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function rename($username, $dbname, MysqlDatabaseRenameRequest $request): JsonResponse
    {
        $user = $this->projectOr404($username);
        $db = $this->databaseOr404($user, $dbname);
        $this->assertIdle($user);

        /** @var array{name: string} $params */
        $params = $request->validated();
        $newName = $user->qualifyMysqlDatabase($params['name']);

        $this->refuseUnusableName($user, $db, $newName);

        $task = Task::start(
            jobType: RenameMysqlDatabase::class,
            queue: 'default',
            username: $user->username,
            details: [
                'action' => 'mysql_database_rename',
                'from' => $db->database,
                'to' => $newName,
            ],
        );
        RenameMysqlDatabase::dispatch($user->username, $db->database, $newName)->attachTask($task);

        return TaskResource::make($task)->response()->setStatusCode(202);
    }

    private function databaseOr404(User $user, string $dbname): MysqlDatabase
    {
        /** @var ?MysqlDatabase $db */
        $db = $user->mysqlDatabases()->getQuery()->where('database', $user->qualifyMysqlDatabase($dbname))->first();
        if (!$db) {
            abort(new JsonResponse([
                'message' => 'MySQL database not found',
            ], 404));
        }

        return $db;
    }

    /**
     * 422 for a prefixed name MySQL reserves, cannot hold, another database of
     * the project already uses, or that only the deploy may own.
     */
    private function refuseUnusableName(User $user, MysqlDatabase $db, string $name): void
    {
        if (in_array(strtolower($name), self::RESERVED_NAMES, true)) {
            throw ProblemException::one('name', 'reserved_name', 'This database name is reserved by MySQL and cannot be used.');
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw ProblemException::one(
                'name',
                'name_too_long',
                'Database name with prefix exceeds ' . self::MAX_NAME_LENGTH . ' characters (MySQL limit). Current length: ' . strlen($name) . ' characters.'
            );
        }

        if ($this->isManagedDatabase($user, $name) || $this->isManagedDatabase($user, $db->database)) {
            throw ProblemException::one(
                'name',
                'managed_database',
                "The application database '" . AppDatabase::nameFor($user) . "' is managed by the deploy and cannot be renamed."
            );
        }

        if ($user->mysqlDatabases()->getQuery()->where('database', $name)->exists()) {
            throw ProblemException::one('name', 'name_taken', 'Database with given name already exists.');
        }
    }

    /** The database AppDatabase::provision() recreates on every deploy. */
    private function isManagedDatabase(User $user, string $name): bool
    {
        if ($user->getTemplate() !== 'dind' && !$user->hasGitProject()) {
            return false;
        }

        return $name === AppDatabase::nameFor($user);
    }

    /**
     * 409 while another job of the project is queued or running, or a deploy holds the account lock.
     */
    private function assertIdle(User $user): void
    {
        $busy = Task::query()
            ->where('username', $user->username)
            ->whereIn('status', [Task::STATUS_QUEUED, Task::STATUS_RUNNING])
            ->orderBy('id')
            ->first();
        if ($busy !== null) {
            throw ProjectBusyException::taskRunning($user->username, $busy);
        }

        if (DeployLogger::isLockedFor($user->username)) {
            throw ProjectBusyException::lockHeld($user->username);
        }
    }
}

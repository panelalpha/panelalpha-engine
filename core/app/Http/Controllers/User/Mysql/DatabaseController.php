<?php

namespace App\Http\Controllers\User\Mysql;

use App\Http\Controllers\Controller;
use App\Http\Requests\MysqlDatabaseStoreRequest;
use App\Http\Resources\MysqlDatabaseCollection;
use App\Http\Resources\MysqlDatabaseResource;
use App\Models\MysqlDatabase;
use App\Models\User;
use App\System;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class DatabaseController extends Controller
{
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
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
                'message' => 'User not found',
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
        $reservedNames = ['information_schema', 'mysql', 'performance_schema', 'sys'];
        if (in_array(strtolower($dbname), $reservedNames)) {
            throw ValidationException::withMessages([
                'name' => 'This database name is reserved by MySQL and cannot be used.',
            ]);
        }

        // Validate final database name length (MySQL limit is 64 characters)
        if (strlen($dbname) > 64) {
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
}

<?php

namespace App\Http\Controllers\User\Mysql;

use App\Http\Controllers\Controller;
use App\Http\Requests\MysqlPrivilegesUpdateRequest;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\User;
use App\System;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PrivilegesController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/mysql/privileges/{dbuser}/{dbname}',
        summary: 'Get MySQL privileges for a user on a database',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Privileges'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbuser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbname', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Privileges data', content: new OA\JsonContent(ref: '#/components/schemas/MysqlPrivileges')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbuser
     * @param string $dbname
     * @return JsonResponse
     */
    public function show($username, $dbuser, $dbname)
    {
        $user = $this->projectOr404($username);

        /** @var ?MysqlUser */
        $dbuser = $user->mysqlUsers()->getQuery()->where('user', $user->qualifyMysqlUser($dbuser))->first();
        if (!$dbuser) {
            abort(404, 'Not found');
        }

        /** @var ?MysqlDatabase */
        $db = $user->mysqlDatabases()->getQuery()->where('database', $user->qualifyMysqlDatabase($dbname))->first();
        if (!$db) {
            abort(404, 'Not found');
        }

        $mysql = (new System())->mysql();
        $privs = $mysql->privileges()->showPrivileges($dbuser->user);
        $data = $privs[$db->database] ?? "";

        return new JsonResponse(['data' => $data]);
    }

    #[OA\Put(
        path: '/projects/{username}/mysql/privileges/{dbuser}/{dbname}',
        summary: 'Update MySQL privileges for a user on a database',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Privileges'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbuser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbname', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['privileges'],
            properties: [new OA\Property(property: 'privileges', type: 'string', example: 'ALL PRIVILEGES')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Privileges updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbuser
     * @param string $dbname
     * @param MysqlPrivilegesUpdateRequest $request
     * @return JsonResponse
     */
    public function update($username, $dbuser, $dbname, MysqlPrivilegesUpdateRequest $request)
    {
        $user = $this->projectOr404($username);

        /** @var ?MysqlUser */
        $dbuser = $user->mysqlUsers()->getQuery()->where('user', $user->qualifyMysqlUser($dbuser))->first();
        if (!$dbuser) {
            abort(404, 'Not found');
        }

        /** @var ?MysqlDatabase */
        $db = $user->mysqlDatabases()->getQuery()->where('database', $user->qualifyMysqlDatabase($dbname))->first();
        if (!$db) {
            abort(404, 'Not found');
        }

        /**
         * @var array{
         *   privileges: string
         * } $params
         */
        $params = $request->validated();

        $mysql = (new System())->mysql();
        $mysql->privileges()->updatePrivileges($dbuser->user, $db->database, $params['privileges']);
        $dbuser->touch();

        return new JsonResponse(['success' => true]);
    }

    #[OA\Delete(
        path: '/projects/{username}/mysql/privileges/{dbuser}/{dbname}',
        summary: 'Revoke all MySQL privileges for a user on a database',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Privileges'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbuser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbname', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Privileges revoked', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbuser
     * @param string $dbname
     * @return JsonResponse
     */
    public function destroy($username, $dbuser, $dbname)
    {
        $user = $this->projectOr404($username);

        /** @var ?MysqlUser */
        $dbuser = $user->mysqlUsers()->getQuery()->where('user', $user->qualifyMysqlUser($dbuser))->first();
        if (!$dbuser) {
            abort(404, 'Not found');
        }

        /** @var ?MysqlDatabase */
        $db = $user->mysqlDatabases()->getQuery()->where('database', $user->qualifyMysqlDatabase($dbname))->first();
        if (!$db) {
            abort(404, 'Not found');
        }

        $mysql = (new System())->mysql();
        $mysql->privileges()->revokeIfExists($dbuser->user, $db->database);
        $dbuser->touch();

        return new JsonResponse(['success' => true]);
    }
}

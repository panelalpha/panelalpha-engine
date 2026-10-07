<?php

namespace App\Http\Controllers\User\Mysql;

use App\Http\Controllers\Controller;
use App\Http\Requests\MysqlUserChangePasswordRequest;
use App\Http\Requests\MysqlUserRenameRequest;
use App\Http\Requests\MysqlUserStoreRequest;
use App\Http\Resources\MysqlUserCollection;
use App\Http\Resources\MysqlUserResource;
use App\Models\MysqlUser;
use App\Models\User;
use App\System;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/mysql/users',
        summary: 'List MySQL users of a project',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Users'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of MySQL users', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MysqlUser'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     */
    public function index($username): MysqlUserCollection
    {
        $user = $this->projectOr404($username);

        $mysql = (new System())->mysql();
        $mysqlUsers = [];
        foreach($user->mysqlUsers as $mysqlUser) {
            $privs = $mysql->privileges()->showPrivileges($mysqlUser->user);
            $mysqlUser->databases = array_keys($privs);
            $mysqlUsers[] = $mysqlUser;
        }

        return new MysqlUserCollection($mysqlUsers);
    }

    #[OA\Get(
        path: '/projects/{username}/mysql/users/{dbuser}',
        summary: 'Get a MySQL user',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Users'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbuser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'MySQL user details', content: new OA\JsonContent(ref: '#/components/schemas/MysqlUser')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbuser
     * @return MysqlUserResource
     */
    public function show($username, $dbuser)
    {
        $user = $this->projectOr404($username);

        /** @var ?MysqlUser $dbuser */
        $dbuser = $user->mysqlUsers()->getQuery()->where('user', $user->qualifyMysqlUser($dbuser))->first();
        if (!$dbuser) {
            abort(404, 'Not found');
        }

        return new MysqlUserResource($dbuser);
    }

    #[OA\Post(
        path: '/projects/{username}/mysql/users',
        summary: 'Create a MySQL user',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Users'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'password'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'dbuser1'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'SecureP@ss1'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'MySQL user created', content: new OA\JsonContent(ref: '#/components/schemas/MysqlUser')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param MysqlUserStoreRequest $request
     * @return MysqlUserResource
     */
    public function store($username, MysqlUserStoreRequest $request)
    {
        $user = $this->projectOr404($username);

        /** @var array{name: string, password: string} */
        $params = $request->validated();
        $dbuser = $params['name'];

        if (!Str::startsWith($dbuser, $user->getMysqlPrefix())) {
            $dbuser = $user->getMysqlPrefix() . $dbuser;
        }

        // Check for reserved MySQL user names
        $reservedNames = ['root', 'mysql', 'admin', 'administrator', 'sys'];
        if (in_array(strtolower($dbuser), $reservedNames)) {
            throw ValidationException::withMessages([
                'name' => 'This username is reserved by MySQL and cannot be used.',
            ]);
        }

        // Validate final MySQL user name length (MySQL limit is 32 characters)
        if (strlen($dbuser) > 32) {
            throw ValidationException::withMessages([
                'name' => 'MySQL user name with prefix exceeds 32 characters (MySQL limit). Current length: ' . strlen($dbuser) . ' characters.',
            ]);
        }

        if ($user->mysqlUsers()->getQuery()->where('user', $dbuser)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'User with given name already exists.',
            ]);
        }

        $mysql = (new System())->mysql();
        $mysql->users()->createUser($dbuser, $params['password']);

        /** @var MysqlUser */
        $dbuser = MysqlUser::create([
            'user_id' => $user->id,
            'user' => $dbuser,
        ]);

        return new MysqlUserResource($dbuser);
    }

    #[OA\Delete(
        path: '/projects/{username}/mysql/users/{dbuser}',
        summary: 'Delete a MySQL user',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Users'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbuser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'MySQL user deleted', content: new OA\JsonContent(ref: '#/components/schemas/MysqlUser')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbuser
     * @return MysqlUserResource
     */
    public function destroy($username, $dbuser)
    {
        $user = $this->projectOr404($username);

        /** @var ?MysqlUser $dbuser */
        $dbuser = $user->mysqlUsers()->getQuery()->where('user', $user->qualifyMysqlUser($dbuser))->first();
        if (!$dbuser) {
            abort(404, 'Not found');
        }

        $mysql = (new System())->mysql();
        $mysql->users()->deleteUser($dbuser->user);
        $dbuser->delete();

        return new MysqlUserResource($dbuser);
    }

    #[OA\Put(
        path: '/projects/{username}/mysql/users/{dbuser}/rename',
        summary: 'Rename a MySQL user',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Users'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbuser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [new OA\Property(property: 'name', type: 'string', example: 'newname')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'MySQL user renamed', content: new OA\JsonContent(ref: '#/components/schemas/MysqlUser')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbuser
     * @param MysqlUserRenameRequest $request
     * @return MysqlUserResource
     */
    public function rename($username, $dbuser, MysqlUserRenameRequest $request)
    {
        $user = $this->projectOr404($username);

        /** @var ?MysqlUser $dbuser */
        $dbuser = $user->mysqlUsers()->getQuery()->where('user', $user->qualifyMysqlUser($dbuser))->first();
        if (!$dbuser) {
            abort(404, 'Not found');
        }

        /**
         * @var array{
         *   name: string
         * } $params
         */
        $params = $request->validated();
        
        $newName = $user->getMysqlPrefix() . $params['name'];

        // Check for reserved MySQL user names
        $reservedNames = ['root', 'mysql', 'admin', 'administrator', 'sys'];
        if (in_array(strtolower($params['name']), $reservedNames)) {
            throw ValidationException::withMessages([
                'name' => 'This username is reserved by MySQL and cannot be used.',
            ]);
        }

        // Validate final MySQL user name length (MySQL limit is 32 characters)
        if (strlen($newName) > 32) {
            throw ValidationException::withMessages([
                'name' => 'MySQL user name with prefix exceeds 32 characters (MySQL limit). Current length: ' . strlen($newName) . ' characters.',
            ]);
        }

        $mysql = (new System())->mysql();
        $mysql->users()->renameUser($dbuser->user, $newName);
        $dbuser->update([
            'user' => $newName,
        ]);

        return new MysqlUserResource($dbuser);
    }

    #[OA\Put(
        path: '/projects/{username}/mysql/users/{dbuser}/change-password',
        summary: 'Change a MySQL user password',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Users'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dbuser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['password'],
            properties: [new OA\Property(property: 'password', type: 'string', format: 'password', example: 'NewSecureP@ss1')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password changed', content: new OA\JsonContent(ref: '#/components/schemas/MysqlUser')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $dbuser
     * @param MysqlUserChangePasswordRequest $request
     * @return MysqlUserResource
     */
    public function changePassword($username, $dbuser, MysqlUserChangePasswordRequest $request)
    {
        $user = $this->projectOr404($username);

        /** @var ?MysqlUser $dbuser */
        $dbuser = $user->mysqlUsers()->getQuery()->where('user', $user->qualifyMysqlUser($dbuser))->first();
        if (!$dbuser) {
            abort(404, 'Not found');
        }

        /**
         * @var array{
         *   password: string
         * } $params
         */
        $params = $request->validated();

        $mysql = (new System())->mysql();
        $mysql->users()->changeUserPassword($dbuser->user, $params['password']);
        $dbuser->touch();

        return new MysqlUserResource($dbuser);
    }
}

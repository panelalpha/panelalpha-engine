<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\SftpAccountStoreRequest;
use App\Http\Requests\SftpAccountUpdateRequest;
use App\Http\Resources\SftpAccountCollection;
use App\Http\Resources\SftpAccountResource;
use App\System;
use App\Models\SftpAccount;
use App\Models\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class SftpAccountController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/sftp-accounts',
        summary: 'List SFTP accounts for a user',
        security: [['bearerAuth' => []]],
        tags: ['SFTP Accounts'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of SFTP accounts', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SftpAccount'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username): SftpAccountCollection
    {
        /** @var ?User */
        $user = User::query()
            ->where('username', $username)
            ->with('sftpAccounts')
            ->first();
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'Project not found',
            ], 404));
        }
        return new SftpAccountCollection($user->sftpAccounts);
    }

    #[OA\Post(
        path: '/projects/{username}/sftp-accounts',
        summary: 'Create an SFTP account',
        security: [['bearerAuth' => []]],
        tags: ['SFTP Accounts'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['sftp_username', 'auth_method'],
            properties: [
                new OA\Property(
                    property: 'sftp_username',
                    type: 'string',
                    example: 'johndoe_backup',
                    description: 'Name of the SFTP account. Must start with "<project username>_". Also accepted as `username`; documented under this name because `username` is already this route\'s path parameter.'
                ),
                new OA\Property(
                    property: 'auth_method',
                    type: 'string',
                    example: 'password',
                    description: 'Comma-separated list of "password" and/or "public_key". Each method listed requires its credential below.'
                ),
                new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true, description: 'Required when auth_method includes "password". Minimum 8 characters.'),
                new OA\Property(property: 'public_key', type: 'string', nullable: true, description: 'Required when auth_method includes "public_key".'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'SFTP account created', content: new OA\JsonContent(ref: '#/components/schemas/SftpAccount')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @return SftpAccountResource
     */
    public function store($username, SftpAccountStoreRequest $request)
    {
        /** @var ?User */
        $user = User::query()->withCount('sftpAccounts')->where('username', $username)->first();
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'Project not found',
            ], 404));
        }

        $this->refuseOverLimit($user);

        /**
         * @var array{
         *   username: string,
         *   auth_method: string,
         *   password: ?string,
         *   public_key: ?string,
         * }
         */
        $params = $request->validated();

        $this->assertAccountNameFree($user, $params['username']);

        $this->authMethods($params);

        if (!empty($params['password'])) {
            $params['password'] = $this->hashPassword($params['password']);
        }

        $sftpAccount = SftpAccount::create([
            'user_id' => $user->id,
            'username' => $params['username'],
            'auth_method' => $params['auth_method'],
            'password' => $params['password'] ?? null,
            'public_key' => $params['public_key'] ?? null,
        ]);

        (new System())->sftp()->rebuildSftpAccounts();

        return new SftpAccountResource($sftpAccount);
    }

    #[OA\Put(
        path: '/projects/{username}/sftp-accounts/{sftpUser}',
        summary: 'Update an SFTP account',
        security: [['bearerAuth' => []]],
        tags: ['SFTP Accounts'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sftpUser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['auth_method'],
            properties: [
                new OA\Property(
                    property: 'auth_method',
                    type: 'string',
                    example: 'password',
                    description: 'Comma-separated list of "password" and/or "public_key". Each method listed requires its credential below; a method left out is turned off.'
                ),
                new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true),
                new OA\Property(property: 'public_key', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'SFTP account updated', content: new OA\JsonContent(ref: '#/components/schemas/SftpAccount')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @return SftpAccountResource
     */
    public function update($username, string $sftpUsername, SftpAccountUpdateRequest $request)
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   auth_method: string,
         *   password: ?string,
         *   public_key: ?string,
         * }
         */
        $params = $request->validated();

        $sftpAccount = $this->accountOr404($user, $sftpUsername);

        [$authPassword, $authKey] = $this->authMethods($params);

        $newParams = ['auth_method' => $params['auth_method']];
        if ($authPassword) {
            $newParams['password'] = $this->hashPassword($params['password']);
        }
        if ($authKey) {
            $newParams['public_key'] = $params['public_key'];
        }

        $sftpAccount->update($newParams);

        (new System())->sftp()->rebuildSftpAccounts();

        return new SftpAccountResource($sftpAccount);
    }

    #[OA\Delete(
        path: '/projects/{username}/sftp-accounts/{sftpUser}',
        summary: 'Delete an SFTP account',
        security: [['bearerAuth' => []]],
        tags: ['SFTP Accounts'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sftpUser', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SFTP account deleted', content: new OA\JsonContent(ref: '#/components/schemas/SftpAccount')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username, string $sftpUsername): SftpAccountResource
    {
        $user = $this->projectOr404($username);

        $sftpAccount = $this->accountOr404($user, $sftpUsername);

        $sftpAccount->delete();

        (new System())->sftp()->rebuildSftpAccounts();

        return new SftpAccountResource($sftpAccount);
    }

    private function accountOr404(User $user, string $sftpUsername): SftpAccount
    {
        /** @var ?SftpAccount */
        $sftpAccount = SftpAccount::query()
            ->where('user_id', $user->id)
            ->where('username', $sftpUsername)
            ->first();

        if (!$sftpAccount) {
            abort(new JsonResponse([
                'message' => 'Account not found',
            ], 404));
        }

        return $sftpAccount;
    }

    private function refuseOverLimit(User $user): void
    {
        $limit = $user->getSftpAccountsLimit();
        if ($limit !== null && $limit <= $user->sftp_accounts_count) {
            abort(new JsonResponse([
                'message' => "SFTP accounts limit of {$limit} reached.",
                'error_type' => 'sftp_accounts_limit_reached',
            ], 422));
        }
    }

    /**
     * An account name is the project's username, an underscore and a non-empty suffix, and is unique on the host.
     *
     * @throws ValidationException
     */
    private function assertAccountNameFree(User $user, string $name): void
    {
        if (
            !Str::startsWith($name, $user->username . '_')
            || !Str::after($name, $user->username . '_')
        ) {
            throw ValidationException::withMessages([
                'username' => 'Username must start with ' . $user->username . '_',
            ]);
        }

        if (SftpAccount::query()->where('username', $name)->exists()) {
            throw ValidationException::withMessages([
                'username' => 'Username not available',
            ]);
        }
    }

    /**
     * Which of password and public-key auth the comma-separated `auth_method` asks for, each with its secret present.
     *
     * @param array{auth_method: string, password: ?string, public_key: ?string} $params
     * @return array{0: bool, 1: bool} password auth, key auth
     * @throws ValidationException
     */
    private function authMethods(array $params): array
    {
        $authPassword = false;
        $authKey = false;
        foreach (explode(',', $params['auth_method']) as $authMethod) {
            if (!in_array($authMethod, ['password', 'public_key'])) {
                throw ValidationException::withMessages([
                    'auth_method' => 'Invalid auth_method',
                ]);
            }
            $authPassword = $authPassword || $authMethod == 'password';
            $authKey = $authKey || $authMethod == 'public_key';
        }

        if ($authPassword && empty($params['password'])) {
            throw ValidationException::withMessages([
                'password' => 'Password is required',
            ]);
        }

        if ($authKey && empty($params['public_key'])) {
            throw ValidationException::withMessages([
                'public_key' => 'Public key is required',
            ]);
        }

        return [$authPassword, $authKey];
    }

    /** SHA-512 crypt (`$6$`, 5000 rounds) with a random salt. */
    private function hashPassword(string $password): string
    {
        $salt = '$6$rounds=5000$' . bin2hex(random_bytes(16)) . '$';

        return crypt($password, $salt);
    }
}

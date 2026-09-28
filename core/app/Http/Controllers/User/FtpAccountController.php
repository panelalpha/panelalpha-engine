<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\FtpAccountStoreRequest;
use App\Http\Requests\FtpAccountUpdateRequest;
use App\Http\Resources\FtpAccountCollection;
use App\Http\Resources\FtpAccountResource;
use App\Models\FtpAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class FtpAccountController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/ftp-accounts',
        summary: 'List FTP accounts for a user',
        security: [['bearerAuth' => []]],
        tags: ['FTP Accounts'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of FTP accounts', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FtpAccount'))],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username): FtpAccountCollection
    {
        /** @var ?User */
        $user = User::query()
            ->where('username', $username)
            ->with('ftpAccounts.userModel')
            ->first();
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }
        return new FtpAccountCollection($user->ftpAccounts);
    }

    #[OA\Post(
        path: '/projects/{username}/ftp-accounts',
        summary: 'Create an FTP account',
        security: [['bearerAuth' => []]],
        tags: ['FTP Accounts'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['user', 'domain', 'password'],
            properties: [
                new OA\Property(property: 'user', type: 'string', example: 'ftpuser1'),
                new OA\Property(property: 'domain', type: 'string', example: 'example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'directory', type: 'string', nullable: true, example: '/public_html'),
                new OA\Property(property: 'unlimited_quota', type: 'boolean', example: true),
                new OA\Property(property: 'quota', type: 'integer', nullable: true, example: 1024, description: 'Quota in MB'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'FTP account created', content: new OA\JsonContent(ref: '#/components/schemas/FtpAccount')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param FtpAccountStoreRequest $request
     * @return FtpAccountResource
     */
    public function store($username, FtpAccountStoreRequest $request)
    {
        /** @var ?User */
        $user = User::query()->withCount('ftpAccounts')->where('username', $username)->first();
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        $limit = $user->getFtpAccountsLimit();
        if ($limit !== null && $limit <= $user->ftp_accounts_count) {
            abort(new JsonResponse([
                'message' => "FTP accounts limit of {$limit} reached.",
                'error_type' => 'ftp_accounts_limit_reached',
            ], 422));
        }

        /**
         * @var array{
         *   user: string,
         *   domain: string,
         *   password: string,
         *   directory: ?string,
         *   unlimited_quota: bool,
         *   quota: ?int
         * }
         */
        $params = $request->validated();

        if (!$user->domains()->getQuery()->where('domain', $params['domain'])->exists()) {
            throw ValidationException::withMessages([
                'domain' => 'Invalid value.',
            ]);
        }

        $ftpUser = "{$params['user']}@{$params['domain']}";
        if ($user->ftpAccounts()->getQuery()->where('user', $ftpUser)->exists()) {
            throw ValidationException::withMessages([
                'user' => 'User already exists.',
            ]);
        }

        $password = $params['password'];
        $directory = '/';
        if (!empty($params['directory'])) {
            $directory .= trim($params['directory'], '/');
        }
        $quota = $request->getQuota();
        $realQuota = $user->getRealFtpAccountQuota($quota);

        $user->project()->ftp()->create($ftpUser, $password, $directory, $realQuota);

        /** @var FtpAccount */
        $ftpAccount = FtpAccount::create([
            'user_id' => $user->id,
            'user' => $ftpUser,
            'directory' => $directory,
            'details' => [
                'unlimited_quota' => ($quota === null),
                'quota' => $realQuota,
            ],
        ]);

        return new FtpAccountResource($ftpAccount);
    }

    #[OA\Put(
        path: '/projects/{username}/ftp-accounts/{ftpUser}',
        summary: 'Update an FTP account',
        security: [['bearerAuth' => []]],
        tags: ['FTP Accounts'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'ftpUser', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'ftpuser1@example.com')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true),
                new OA\Property(property: 'unlimited_quota', type: 'boolean'),
                new OA\Property(property: 'quota', type: 'integer', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'FTP account updated', content: new OA\JsonContent(ref: '#/components/schemas/FtpAccount')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $ftpUser
     * @param FtpAccountUpdateRequest $request
     * @return FtpAccountResource
     */
    public function update($username, $ftpUser, FtpAccountUpdateRequest $request)
    {
        $user = $this->projectOr404($username);

        /** @var ?FtpAccount */
        $ftpAccount = $user->ftpAccounts()->getQuery()->where('user', $ftpUser)->first();
        if (!$ftpAccount) {
            abort(new JsonResponse([
                'message' => 'FTP account not found',
            ], 404));
        }

        /**
         * @var array{
         *   password: ?string,
         *   unlimited_quota: bool,
         *   quota: ?int
         * }
         */
        $params = $request->validated();

        if ($request->quotaProvided()) {
            $quota = $request->getQuota();
        } else {
            // Neither field was sent: keep the account's current quota
            // instead of letting getQuota()'s int cast silently zero it out.
            $existing = $ftpAccount->getDetails();
            $quota = ($existing['unlimited_quota'] ?? false) ? null : ($existing['quota'] ?? null);
        }
        $realQuota = $user->getRealFtpAccountQuota($quota);
        $password = $params['password'] ?? null;

        $user->project()->ftp()->update($ftpAccount->user, $password, $realQuota);
        $ftpAccount->setDetails([
            'unlimited_quota' => ($quota === null),
            'quota' => $realQuota,
        ]);
        $ftpAccount->save();

        return new FtpAccountResource($ftpAccount);
    }

    #[OA\Delete(
        path: '/projects/{username}/ftp-accounts/{ftpUser}',
        summary: 'Delete an FTP account',
        security: [['bearerAuth' => []]],
        tags: ['FTP Accounts'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'ftpUser', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'ftpuser1@example.com')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'FTP account deleted', content: new OA\JsonContent(ref: '#/components/schemas/FtpAccount')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $ftpUser
     * @return FtpAccountResource
     */
    public function destroy($username, $ftpUser)
    {
        $user = $this->projectOr404($username);

        /** @var ?FtpAccount */
        $ftpAccount = $user->ftpAccounts()->getQuery()->where('user', $ftpUser)->first();
        if (!$ftpAccount) {
            abort(new JsonResponse([
                'message' => 'FTP account not found',
            ], 404));
        }

        $user->project()->ftp()->delete($ftpAccount->user);
        $ftpAccount->delete();

        return new FtpAccountResource($ftpAccount);
    }
}

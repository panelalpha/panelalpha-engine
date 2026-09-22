<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileExistsRequest;
use App\Http\Requests\FileRemoveRequest;
use App\Http\Requests\Files\ChmodRequest;
use App\Http\Requests\Files\CpRequest;
use App\Http\Requests\Files\DownloadRequest;
use App\Http\Requests\Files\FetchRequest;
use App\Http\Requests\Files\MkdirRequest;
use App\Http\Requests\Files\MoveContentsRequest;
use App\Http\Requests\Files\MvRequest;
use App\Http\Requests\Files\PutContentsRequest;
use App\Http\Requests\Files\StatRequest;
use App\Http\Requests\Files\UploadRequest;
use App\Http\Requests\Files\ZipRequest;
use App\System as EngineSystem;
use App\Lib\Helpers\FileStreamWrapper;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class FileController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/files/exists',
        summary: 'Check if a file or directory exists',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Existence check result', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'exists', type: 'boolean')],
            )),
        ],
    )]
    public function exists(string $username, FileExistsRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{path: string}
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);

        $fileMan = $user->project()->fileManager();
        try {
            $exists = $fileMan->exists($path);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'exists' => $exists,
            'path' => $path,
        ]);
    }

    #[OA\Delete(
        path: '/projects/{username}/files/remove',
        summary: 'Delete a file or directory',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function remove(string $username, FileRemoveRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   path: string,
         *   recursive?: bool,
         * }
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);
        if (!file_exists($path)) {
            return new JsonResponse([
                'message' => 'Invalid path',
            ], 404);
        }

        $fileMan = $user->project()->fileManager();

        try {
            $fileMan->remove($path, !empty($params['recursive']));
            if (basename($path) === '.htaccess') {
                $this->handleHtaccess($path);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Post(
        path: '/projects/{username}/files/mkdir',
        summary: 'Create a directory',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['path'],
            properties: [
                new OA\Property(property: 'path', type: 'string', example: '/public_html/newdir'),
                new OA\Property(property: 'parents', type: 'boolean', example: false),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Directory created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function mkdir(string $username, MkdirRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   path: string,
         *   parents?: bool,
         * }
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);

        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->mkdir($path, !empty($params['parents']));
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Post(
        path: '/projects/{username}/files/zip',
        summary: 'Create a ZIP archive',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['zip_path', 'path'],
            properties: [
                new OA\Property(property: 'zip_path', type: 'string', example: '/public_html/backup.zip'),
                new OA\Property(property: 'path', type: 'string', example: '/public_html/dir'),
                new OA\Property(property: 'compression_level', type: 'integer', example: 6),
                new OA\Property(property: 'from_date', type: 'string', example: '2026-01-01'),
                new OA\Property(property: 'ignore_empty', type: 'boolean', example: false),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'ZIP created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function zip(string $username, ZipRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   zip_path: string,
         *   path: string,
         *   compression_level?: int,
         *   from_date?: string,
         *   ignore_empty?: bool,
         * }
         */
        $params = $request->validated();
        $zipPath = $user->project()->resolvePath($params['zip_path']);
        $path = $user->project()->resolvePath($params['path']);

        $fileMan = $user->project()->fileManager();
        try {
            $level = $params['compression_level'] ?? null;
            $fileMan->zip(
                $zipPath,
                $path,
                true,
                $level === null ? null : (int) $level,
                $params['from_date'] ?? null,
                !empty($params['ignore_empty']),
            );
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Post(
        path: '/projects/{username}/files/unzip',
        summary: 'Extract a ZIP archive',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['zip_path', 'path'],
            properties: [
                new OA\Property(property: 'zip_path', type: 'string', example: '/public_html/backup.zip'),
                new OA\Property(property: 'path', type: 'string', example: '/public_html'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'ZIP extracted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function unzip(string $username, ZipRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   zip_path: string,
         *   path: string,
         * }
         */
        $params = $request->validated();
        $zipPath = $user->project()->resolvePath($params['zip_path']);
        $path = $user->project()->resolvePath($params['path']);

        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->unzip($zipPath, $path);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Post(
        path: '/projects/{username}/files/move-contents',
        summary: 'Move the immediate children of a directory',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['source_path', 'dest_path'],
            properties: [
                new OA\Property(property: 'source_path', type: 'string', example: '/public_html/incoming'),
                new OA\Property(property: 'dest_path', type: 'string', example: '/public_html'),
                new OA\Property(property: 'override', type: 'boolean', example: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Children moved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function moveContents(string $username, MoveContentsRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   source_path: string,
         *   dest_path: string,
         *   override?: bool,
         * }
         */
        $params = $request->validated();
        $source = $user->project()->resolvePath($params['source_path']);
        $dest = $user->project()->resolvePath($params['dest_path']);

        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->moveDirectoryContents($source, $dest, $params['override'] ?? true);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Post(
        path: '/projects/{username}/files/fetch',
        summary: 'Fetch an http or https URL into the project',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['url', 'path'],
            properties: [
                new OA\Property(property: 'url', type: 'string', example: 'https://example.com/plugin.zip'),
                new OA\Property(property: 'path', type: 'string', example: '/public_html'),
                new OA\Property(property: 'filename', type: 'string', example: 'plugin.zip'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'File fetched', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function fetch(string $username, FetchRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   url: string,
         *   path: string,
         *   filename?: string,
         * }
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);

        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->fetch($params['url'], $path, $params['filename'] ?? null);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Put(
        path: '/projects/{username}/files/chmod',
        summary: 'Set the mode of a file or directory',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['path', 'mode'],
            properties: [
                new OA\Property(property: 'path', type: 'string', example: '/public_html/script.sh'),
                new OA\Property(property: 'mode', type: 'string', example: '755'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Mode set', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function chmod(string $username, ChmodRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   path: string,
         *   mode: string,
         * }
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);

        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->chmod($path, $params['mode']);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Put(
        path: '/projects/{username}/files/mv',
        summary: 'Move or rename a file or directory',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['source_path', 'dest_path'],
            properties: [
                new OA\Property(property: 'source_path', type: 'string', example: '/public_html/old.txt'),
                new OA\Property(property: 'dest_path', type: 'string', example: '/public_html/new.txt'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Moved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function mv(string $username, MvRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   source_path: string,
         *   dest_path: string,
         * }
         */
        $params = $request->validated();
        $sourcePath = $user->project()->resolvePath($params['source_path']);
        $destPath = $user->project()->resolvePath($params['dest_path']);

        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->mv($sourcePath, $destPath);
            if (basename($sourcePath) === '.htaccess') {
                $this->handleHtaccess($sourcePath);
            }
            if (basename($destPath) === '.htaccess') {
                $this->handleHtaccess($destPath);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Put(
        path: '/projects/{username}/files/cp',
        summary: 'Copy a file or directory',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['source_path', 'dest_path'],
            properties: [
                new OA\Property(property: 'source_path', type: 'string'),
                new OA\Property(property: 'dest_path', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Copied', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function cp(string $username, CpRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   source_path: string,
         *   dest_path: string,
         * }
         */
        $params = $request->validated();
        $sourcePath = $user->project()->resolvePath($params['source_path']);
        $destPath = $user->project()->resolvePath($params['dest_path']);

        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->cp($sourcePath, $destPath);
            if (basename($destPath) === '.htaccess') {
                $this->handleHtaccess($destPath);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Get(
        path: '/projects/{username}/files/stat',
        summary: 'Get file or directory stats',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File stats', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'size', type: 'integer'),
                        new OA\Property(property: 'type', type: 'string', enum: ['file', 'dir']),
                        new OA\Property(property: 'modified', type: 'integer'),
                    ]),
                ],
            )),
        ],
    )]
    public function stat(string $username, StatRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   path: string,
         * }
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);
        if (!file_exists($path)) {
            return new JsonResponse([
                'message' => 'Invalid path',
            ], 404);
        }

        $fileMan = $user->project()->fileManager();
        try {
            $result = $fileMan->stat($path);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse($result);
    }

    #[OA\Post(
        path: '/projects/{username}/files/upload',
        summary: 'Upload a file',
        description: 'Stores one file in the account, under `path` (relative to the home directory, e.g. /project). Over HTTP the file is a multipart form part. Over MCP pass file_name plus file_contents (base64, or text with file_encoding: text) for a small file, or file_url for anything the engine should download itself, such as a release archive; then project_deploy_archive deploys an uploaded .zip or .tar.gz. Plain text files can also be written directly with file_write.',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['path', 'file'],
                properties: [
                    new OA\Property(property: 'path', type: 'string', example: '/public_html'),
                    new OA\Property(property: 'file', type: 'string', format: 'binary'),
                ],
            ),
        )),
        responses: [
            new OA\Response(response: 200, description: 'File uploaded', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function upload(string $username, UploadRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   path: string,
         *   file: UploadedFile,
         * }
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);
        /** @var \Illuminate\Http\UploadedFile */
        $file = $request->file('file');
        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->moveUploadedFile($path, $file);
            if ($file->getClientOriginalName() === '.htaccess') {
                $this->handleHtaccess($path);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[OA\Get(
        path: '/projects/{username}/files/download',
        summary: 'Download a file',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File download (binary)', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'File not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @return BinaryFileResponse|JsonResponse
     */
    public function download(string $username, DownloadRequest $request)
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   path: string,
         * }
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);

        $system = new EngineSystem();
        if (!$system->filesystem()->fileExists($path)) {
            return new JsonResponse([
                'message' => 'Invalid path',
            ], 404);
        }

        FileStreamWrapper::register();

        /** @var BinaryFileResponse */
        return response()->download('sudophp://' . $path);
    }

    #[OA\Put(
        path: '/projects/{username}/files/put-contents',
        summary: 'Write content to a file',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['path', 'contents'],
            properties: [
                new OA\Property(property: 'path', type: 'string', example: '/public_html/index.php'),
                new OA\Property(property: 'contents', type: 'string', example: '<?php echo "Hello world";'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'File written', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function putContents(string $username, PutContentsRequest $request): JsonResponse
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        /**
         * @var array{
         *   path: string,
         *   contents: string,
         * }
         */
        $params = $request->validated();
        $path = $user->project()->resolvePath($params['path']);

        $fileMan = $user->project()->fileManager();
        try {
            $fileMan->putContents($path, $params['contents']);
            if (basename($path) === '.htaccess') {
                $this->handleHtaccess($path);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }

    private function handleHtaccess(string $path): void
    {
        try {
            $system = new EngineSystem();
            if ($system->webserver()->getCurrentWebserver() === 'openlitespeed') {
                $system->webserver()->scheduleWebserverReloadInBackground();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to schedule OpenLiteSpeed reload after .htaccess change', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

}

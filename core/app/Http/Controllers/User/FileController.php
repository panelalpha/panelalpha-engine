<?php

namespace App\Http\Controllers\User;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FileExistsRequest;
use App\Http\Requests\FileRemoveRequest;
use App\Http\Requests\Files\ChmodRequest;
use App\Http\Requests\Files\CpRequest;
use App\Http\Requests\Files\DownloadRequest;
use App\Http\Requests\Files\FetchRequest;
use App\Http\Requests\Files\ListRequest;
use App\Http\Requests\Files\MkdirRequest;
use App\Http\Requests\Files\MoveContentsRequest;
use App\Http\Requests\Files\MvRequest;
use App\Http\Requests\Files\PutContentsRequest;
use App\Http\Requests\Files\StatRequest;
use App\Http\Requests\Files\ThumbnailRequest;
use App\Http\Requests\Files\UploadRequest;
use App\Http\Requests\Files\ZipRequest;
use App\System as EngineSystem;
use App\Lib\Project\FileThumbnail;
use App\Lib\Project\ProjectFiles;
use App\Lib\Net\EgressRefusedException;
use App\System\Project\FileManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\ValidationException;
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
    public function exists(string $username, FileExistsRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{path: string}
         */
        $params = $request->validated();
        $path = $user->project($system)->resolvePath($params['path']);

        $fileMan = $user->project($system)->fileManager();
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
            new OA\Parameter(name: 'recursive', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'), description: 'Required to delete a directory; deletes everything in it.'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'A directory without recursive', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function remove(string $username, FileRemoveRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   path: string,
         *   recursive?: bool,
         * }
         */
        $params = $request->validated();
        $path = $user->project($system)->resolvePath($params['path']);

        $fileMan = $user->project($system)->fileManager();

        try {
            $fileMan->remove($path, !empty($params['recursive']));
            if (basename($path) === '.htaccess') {
                ProjectFiles::htaccessChanged($path);
            }
        } catch (NotFoundException) {
            return new JsonResponse([
                'message' => 'Invalid path',
            ], 404);
        } catch (ValidationException $e) {
            throw $e;
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
        $user = $this->projectOr404($username);

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
        $user = $this->projectOr404($username);

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
        $user = $this->projectOr404($username);

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
        $user = $this->projectOr404($username);

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
        description: 'Download an http(s) URL into the project as the account. The engine fetches it from core, '
            . 'so it refuses a URL whose host resolves to an internal address (loopback, link-local, a private or '
            . 'reserved range, the Docker and tenant networks) or that redirects to one. The refusal is 422 with '
            . '`problems[{field: url, code: host_refused}]`; RFC1918/ULA are allowed only with `EGRESS_ALLOW_PRIVATE=true`.',
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
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or a refused destination host', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function fetch(string $username, FetchRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   url: string,
         *   path: string,
         *   filename?: string,
         * }
         */
        $params = $request->validated();
        $path = $user->project($system)->resolvePath($params['path']);

        $fileMan = $user->project($system)->fileManager();
        try {
            $fileMan->fetch($params['url'], $path, $params['filename'] ?? null);
        } catch (EgressRefusedException $e) {
            throw ProblemException::one('url', EgressRefusedException::CODE, $e->getMessage());
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
        $user = $this->projectOr404($username);

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
        $user = $this->projectOr404($username);

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
                ProjectFiles::htaccessChanged($sourcePath);
            }
            if (basename($destPath) === '.htaccess') {
                ProjectFiles::htaccessChanged($destPath);
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
        $user = $this->projectOr404($username);

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
                ProjectFiles::htaccessChanged($destPath);
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
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FileEntry')],
            )),
            new OA\Response(response: 404, description: 'Invalid path', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function stat(string $username, StatRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   path: string,
         * }
         */
        $params = $request->validated();
        $path = $user->project($system)->resolvePath($params['path']);

        $fileMan = $user->project($system)->fileManager();
        try {
            $result = $fileMan->stat($path);
        } catch (NotFoundException) {
            return new JsonResponse([
                'message' => 'Invalid path',
            ], 404);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'data' => $result,
        ]);
    }

    #[OA\Get(
        path: '/projects/{username}/files/list',
        summary: 'List the entries of a directory',
        description: 'Classic and dind projects alike: reads the account home, as the account. Directories come '
            . 'first, then names in byte order; dot-entries are included unless show_hidden is false. Refuses a '
            . 'path that resolves outside the home, and a path that is not a directory.',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'show_hidden', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5000, minimum: 1, maximum: 20000)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Directory entries', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FileEntry')),
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'path', type: 'string', example: '/example.com/public_html'),
                        new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the directory holds more entries than the limit returned.'),
                        new OA\Property(property: 'total', type: 'integer', description: 'Entries in the directory after the show_hidden filter, before the limit.'),
                    ]),
                ],
            )),
            new OA\Response(response: 404, description: 'Invalid path', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Not a directory, or a path outside the home', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function list(string $username, ListRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   path: string,
         *   show_hidden?: bool,
         *   limit?: int,
         * }
         */
        $params = $request->validated();
        $path = $user->project($system)->resolvePath($params['path']);

        $fileMan = $user->project($system)->fileManager();
        try {
            $result = $fileMan->list(
                $path,
                filter_var($params['show_hidden'] ?? true, FILTER_VALIDATE_BOOLEAN),
                (int) ($params['limit'] ?? FileManager::LIST_DEFAULT_LIMIT),
            );
        } catch (NotFoundException) {
            return new JsonResponse([
                'message' => 'Invalid path',
            ], 404);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'data' => $result['entries'],
            'meta' => [
                'path' => $result['path'],
                'truncated' => $result['truncated'],
                'total' => $result['total'],
            ],
        ]);
    }

    #[OA\Post(
        path: '/projects/{username}/files/upload',
        summary: 'Upload a file',
        description: 'Stores one file in the account, under `path` (relative to the home directory, e.g. /project). Over HTTP the file is a multipart form part. Over MCP pass file_name plus file_contents (base64, or text with file_encoding: text) for a small file, or file_url for anything the engine should download itself, such as a release archive; then project_deploy_archive deploys an uploaded .zip or .tar.gz. Plain text files can also be written directly with file_write.',
        x: ['mcp-description' => 'Stores one file under `path` (e.g. /project). Small: file_name + file_contents. '
            . 'Anything larger: file_url, which the engine downloads.'],
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
        $user = $this->projectOr404($username);

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
        try {
            ProjectFiles::upload($user, $path, $file);
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
    public function download(string $username, DownloadRequest $request, EngineSystem $system)
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   path: string,
         * }
         */
        $params = $request->validated();
        $path = $user->project($system)->resolvePath($params['path']);

        $source = ProjectFiles::readablePath($user, $path, $system);
        if ($source === null) {
            return new JsonResponse([
                'message' => 'Invalid path',
            ], 404);
        }

        try {
            /** @var BinaryFileResponse */
            return response()->download($source);
        } catch (FileNotFoundException) {
            // `test -f` follows a symlink out of the home; the wrapper refuses it.
            return new JsonResponse([
                'message' => 'Invalid path',
            ], 404);
        }
    }

    #[OA\Get(
        path: '/projects/{username}/files/thumbnail',
        summary: 'Get a webp thumbnail of an image file',
        description: 'Resizes a png, jpeg, gif or webp file into a cached webp thumbnail for the file-manager grid. '
            . 'Supports every project kind. Refuses a file that is not one of those formats by content with '
            . '`unsupported_image`, and one larger than 50 MB or 50 megapixels with `image_too_large`. Binary: '
            . 'no MCP tool.',
        // A tool answers with JSON; this returns image bytes. file_download covers MCP file reads.
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'width', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: ThumbnailRequest::DEFAULT_WIDTH, minimum: ThumbnailRequest::MIN_SIZE, maximum: ThumbnailRequest::MAX_SIZE)),
            new OA\Parameter(name: 'height', in: 'query', required: false, description: 'Defaults to width.', schema: new OA\Schema(type: 'integer', default: ThumbnailRequest::DEFAULT_WIDTH, minimum: ThumbnailRequest::MIN_SIZE, maximum: ThumbnailRequest::MAX_SIZE)),
            new OA\Parameter(name: 'fit', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ThumbnailRequest::FITS, default: 'cover')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Webp thumbnail (binary)', content: new OA\MediaType(mediaType: 'image/webp', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 304, description: 'The thumbnail is unchanged; the ETag matched If-None-Match'),
            new OA\Response(response: 404, description: 'File not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Not a supported image, or too large', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function thumbnail(string $username, ThumbnailRequest $request, EngineSystem $system): Response
    {
        $user = $this->projectOr404($username);

        /** @var array{path: string, width: int, height: int, fit: string} $params */
        $params = $request->validated();
        $path = $user->project($system)->resolvePath($params['path']);
        $width = (int) $params['width'];
        $height = (int) $params['height'];
        $thumbnails = new FileThumbnail($system);

        $etag = $thumbnails->etag($user, $path, $width, $height, $params['fit']);
        if ($etag === null) {
            return new JsonResponse(['message' => 'Invalid path'], 404);
        }

        $response = new Response('', 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=86400',
        ]);
        $response->setEtag($etag);
        if ($response->isNotModified($request)) {
            return $response;
        }

        $body = $thumbnails->render($user, $path, $width, $height, $params['fit']);
        if ($body === null) {
            return new JsonResponse(['message' => 'Invalid path'], 404);
        }

        return $response->setContent($body);
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
        $user = $this->projectOr404($username);

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
                ProjectFiles::htaccessChanged($path);
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

}

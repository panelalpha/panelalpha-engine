<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadStoreRequest;
use App\Lib\Upload\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Archives uploaded before their project exists; a create's archive source or
 * PUT /projects/{username}/source takes one by its `upload_id`.
 */
class UploadController extends Controller
{
    #[OA\Post(
        path: '/uploads',
        summary: 'Upload an archive for a project not created yet',
        description: 'Stores a .zip, .tar.gz or .tgz archive on the engine and answers its `id`. Pass the id as '
            .'`source.upload_id` of an archive source on POST /projects (or PUT /projects/{username}/source): the '
            .'create moves the archive into the new project and deploys it, and the upload is gone afterwards. '
            .'An upload no create used expires after `expires_at` (24 hours) and is deleted. The file name decides '
            .'the format, so it must end in .zip, .tar.gz or .tgz. Over MCP pass file_name plus file_contents '
            .'(base64), or file_url for the engine to download; a create can also take an archive URL directly '
            .'as `source.url`.',
        security: [['bearerAuth' => []]],
        tags: ['Uploads'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary'),
                ],
            ),
        )),
        responses: [
            new OA\Response(response: 201, description: 'Archive stored', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Upload')],
            )),
            new OA\Response(response: 422, description: 'No file, or not named as an archive (`file_not_an_archive`)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
        x: ['mcp-description' => 'Stores an archive (.zip, .tar.gz, .tgz) for a project not created yet; pass the returned id '
            .'as source.upload_id of an archive source on project_create. Small: file_name + file_contents. Larger: '
            .'file_url, or skip this and give project_create source.url directly.'],
    )]
    public function store(UploadStoreRequest $request, Uploads $uploads): JsonResponse
    {
        return new JsonResponse(['data' => $uploads->store($request->archive())->toApi()], 201);
    }

    #[OA\Get(
        path: '/uploads/{id}',
        summary: 'Get an upload',
        description: 'An upload\'s file name, size, the project that claimed it (null until a create names it) and '
            .'when it expires. 404 once a create has moved it into its project, or after it expired.',
        security: [['bearerAuth' => []]],
        tags: ['Uploads'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The upload', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Upload')],
            )),
            new OA\Response(response: 404, description: 'No such upload', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $id, Uploads $uploads): JsonResponse
    {
        return new JsonResponse(['data' => $uploads->findOrFail($id)->toApi()]);
    }

    #[OA\Delete(
        path: '/uploads/{id}',
        summary: 'Delete an upload',
        description: 'Removes an upload no create has claimed. 409 when a create already claimed it: its deploy moves the file into the project.',
        security: [['bearerAuth' => []]],
        tags: ['Uploads'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'No such upload', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Claimed by a create (`upload_claimed`)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function destroy(string $id, Uploads $uploads): Response
    {
        $uploads->deleteUnclaimed($id);

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\User\Domain;

use App\Http\Controllers\Controller;
use App\Lib\Domains\DomainLogFiles;
use App\System as EngineSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class LogFileController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/log-files',
        summary: 'List log files for a domain',
        security: [['bearerAuth' => []]],
        tags: ['Domain Log Files'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'all_webservers', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of log files', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
        ],
    )]
    /**
     * @param  string  $username
     * @param  string  $domain
     * @return JsonResponse
     */
    public function index(Request $request, $username, $domain, EngineSystem $system)
    {
        $params = $request->validate([
            'all_webservers' => 'nullable',
        ]);

        $user = $this->projectOr404($username);
        $logFiles = DomainLogFiles::find($user, $domain, $system) ?? abort(404, 'Not found');

        return new JsonResponse(['data' => $logFiles->list(! empty($params['all_webservers']))]);
    }

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/log-files/{filename}',
        summary: 'Download a domain log file',
        security: [['bearerAuth' => []]],
        tags: ['Domain Log Files'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filename', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Log file download', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
     * @param  string  $domain
     * @param  string  $filename
     * @return BinaryFileResponse
     */
    public function download(Request $request, $username, $domain, $filename, EngineSystem $system)
    {
        $params = $request->validate([
            'all_webservers' => 'nullable',
        ]);

        $user = $this->projectOr404($username);
        $logFiles = DomainLogFiles::find($user, $domain, $system) ?? abort(404, 'Not found');
        $path = $logFiles->readablePath($filename, ! empty($params['all_webservers'])) ?? abort(404, 'Not found');

        try {
            /** @var BinaryFileResponse */
            return response()->download($path, $filename);
        } catch (FileNotFoundException) {
            // The file was swapped for a link after the listing: the wrapper refuses it.
            abort(404, 'Not found');
        }
    }
}

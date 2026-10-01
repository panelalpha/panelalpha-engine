<?php

namespace App\Http\Controllers\User\Domain;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use OpenApi\Attributes as OA;

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
     * @param Request $request
     * @param string $username
     * @param string $domain
     * @return JsonResponse
     */
    public function index(Request $request, $username, $domain)
    {
        $params = $request->validate([
            'all_webservers' => 'nullable',
        ]);

        $user = $this->projectOr404($username);

        /** @var ?Domain $domain */
        $domain = $user->domains()->getQuery()->where('domain', $domain)->first();
        if (!$domain) {
            abort(404, 'Not found');
        }

        $projectDomain = $domain->projectDomain();

        $logFiles = empty($params['all_webservers']) ? $projectDomain->listLogFiles() : $projectDomain->listWebserverLogFiles();

        return new JsonResponse(['data' => $logFiles]);
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
     * @param Request $request
     * @param string $username
     * @param string $domain
     * @param string $filename
     * @return BinaryFileResponse
     */
    public function download(Request $request, $username, $domain, $filename)
    {
        $params = $request->validate([
            'all_webservers' => 'nullable',
        ]);

        $user = $this->projectOr404($username);

        /** @var ?Domain $domain */
        $domain = $user->domains()->getQuery()->where('domain', $domain)->first();
        if (!$domain) {
            abort(404, 'Not found');
        }

        $projectDomain = $domain->projectDomain();

        $logFiles = empty($params['all_webservers']) ? $projectDomain->listLogFiles() : $projectDomain->listWebserverLogFiles();
        foreach ($logFiles as $logFile) {
            if ($logFile['file'] == $filename) {
                /** @var BinaryFileResponse */
                return response()->download($logFile['path']);
            }
        }
        abort(404, 'Not found');
    }
}

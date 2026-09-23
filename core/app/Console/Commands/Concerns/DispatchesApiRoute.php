<?php

namespace App\Console\Commands\Concerns;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared plumbing for the commands that stand in for API endpoints a JSON
 * request cannot express: an upload has to carry a real file, and a download
 * answers with a BinaryFileResponse or a StreamedResponse, both of which return
 * false from getContent() - which is precisely why `api:call` cannot fetch a
 * file and these commands exist.
 */
trait DispatchesApiRoute
{
    /**
     * Dispatch an /api route in-process as the root admin, the way api:call
     * does, but with room for uploaded files.
     *
     * @param array<string, mixed> $params
     * @param array<string, \Illuminate\Http\UploadedFile> $files
     */
    protected function dispatchApiRoute(string $method, string $uri, array $params = [], array $files = []): Response
    {
        // The default guard is 'api', so setting the user here satisfies the
        // auth:api middleware the route still runs under. rootAccount(), not
        // the first admins row: a fresh install has none until a token is
        // minted, and every command here died on a TypeError until then --
        // `installer.sh --repo` included (engine#225, #224).
        Auth::setUser(Admin::rootAccount());

        $request = Request::create("/api{$uri}", strtoupper($method), $params, [], $files);
        App::instance('request', $request);

        return Route::dispatch($request);
    }

    /**
     * Send a response body to a file or to stdout, streaming rather than
     * buffering so a large file does not have to fit in memory.
     */
    protected function writeResponseBody(Response $response, ?string $out): int
    {
        if ($response->getStatusCode() >= 400) {
            $this->error($this->errorMessage($response));
            return 1;
        }

        if (!$response instanceof BinaryFileResponse && !$response instanceof StreamedResponse) {
            $content = $response->getContent();
            $content = $content === false ? '' : $content;
            if ($out === null) {
                $this->output->write($content);
                return 0;
            }
            if (file_put_contents($out, $content) === false) {
                $this->error("Could not write {$out}");
                return 1;
            }
            $this->info("Saved to {$out}");
            return 0;
        }

        if ($out === null) {
            $response->sendContent();
            return 0;
        }

        $handle = fopen($out, 'wb');
        if ($handle === false) {
            $this->error("Could not open {$out} for writing");
            return 1;
        }
        try {
            ob_start(function (string $chunk) use ($handle): string {
                fwrite($handle, $chunk);
                return '';
            }, 8192);
            $response->sendContent();
            ob_end_flush();
        } finally {
            fclose($handle);
        }

        $this->info(sprintf('Saved to %s (%s bytes)', $out, number_format((int) filesize($out))));
        return 0;
    }

    /** Pull the API's own message out of an error response, if it sent one. */
    protected function errorMessage(Response $response): string
    {
        $body = $response->getContent();
        if (is_string($body) && $body !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
                return sprintf('HTTP %d: %s', $response->getStatusCode(), $decoded['message']);
            }
            return sprintf('HTTP %d: %s', $response->getStatusCode(), substr($body, 0, 300));
        }

        return sprintf('HTTP %d', $response->getStatusCode());
    }
}

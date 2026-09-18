<?php

namespace App\Mcp\Tools\Api;

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Base for the generated one-tool-per-endpoint API surface.
 *
 * The endpoint is fixed per subclass -- method and path are compile-time
 * constants written by mcp:tool:generate from the OpenAPI annotations, and
 * only declared parameters are ever substituted into them. That is the whole
 * point of generating a tool per operation rather than reviving a single
 * "call any path" proxy: there is no caller-supplied path to traverse out of,
 * and each operation carries its own validated schema.
 *
 * Calls are dispatched through the router in-process. No loopback HTTP, so no
 * second TLS handshake to disable verification on, and no way to be pointed at
 * a host other than this application. The caller's bearer token is forwarded so
 * the API's own auth and any per-route authorisation still decide the outcome:
 * reaching a tool is not the same as being allowed to run it.
 */
abstract class ApiTool extends Tool
{
    /**
     * Marks a sub-request as having come from an MCP tool.
     *
     * Nothing authorises on it -- the forwarded bearer token still decides
     * every outcome -- and a REST caller is free to set it themselves. It
     * exists so an endpoint that records *which surface* asked, as a bug
     * report does, does not see every MCP call as an ordinary API call.
     */
    public const VIA_HEADER = 'X-PanelAlpha-Via';

    public const VIA_MCP = 'mcp';

    /** Marks a request as this class's own in-process dispatch. */
    public const VIA_ATTRIBUTE = 'panelalpha.via_mcp';

    /** Largest file a tool returns inline; base64 grows it by a third. */
    public const MAX_DOWNLOAD_BYTES = 1048576;

    /** The HTTP verb this tool performs. */
    abstract protected function method(): string;

    /**
     * The verb, readable from outside. ToolPolicy needs it to apply
     * MCP_PERMISSION_MODE: the readOnly/destructive annotations cannot tell a
     * DELETE from a PUT, and "modify" has to allow one and not the other.
     */
    public function httpMethod(): string
    {
        return $this->method();
    }

    /** The API path, relative to /api, with {placeholders} for path parameters. */
    abstract protected function path(): string;

    /** @return array<int, string> Names of parameters substituted into the path. */
    protected function pathParams(): array
    {
        return [];
    }

    /** @return array<int, string> Names of parameters sent as query string. */
    protected function queryParams(): array
    {
        return [];
    }

    /** @return array<int, string> Names of parameters sent in the JSON body. */
    protected function bodyParams(): array
    {
        return [];
    }

    /**
     * Names of the endpoint's multipart file fields. Each one is exposed to the
     * model as the virtual arguments {@see UploadArguments} describes, and the
     * call goes out as multipart/form-data instead of JSON.
     *
     * @return array<int, string>
     */
    protected function fileParams(): array
    {
        return [];
    }

    /**
     * Parameters the tool exposes under a different name than the API knows
     * them by: tool argument => API parameter. The project is the one that
     * matters: every endpoint takes it as `username`, a leftover from when a
     * project was called a user, and the tools take it as `name`. The
     * declarations above use the API's names; the schema and the rules use
     * the tool's, and dispatch() translates between them.
     *
     * @return array<string, string>
     */
    protected function argumentNames(): array
    {
        return [];
    }

    /**
     * Values sent when the caller leaves a parameter out, keyed by API name.
     * Generated from `x-mcp-default` on the OpenAPI property, so an agent can
     * get a different default from a REST caller where that fits.
     *
     * @return array<string, string>
     */
    protected function defaults(): array
    {
        return [];
    }

    /** The name the tool exposes for an API parameter. */
    protected function argument(string $apiName): string
    {
        return array_search($apiName, $this->argumentNames(), true) ?: $apiName;
    }

    public function handle(Request $request): Response
    {
        $input = $this->apiInput($request->validate($this->rules()));

        try {
            $response = $this->dispatch($input);
        } catch (InvalidArgumentException $e) {
            // A malformed upload argument is the caller's mistake, not an
            // incident: say what is wrong and leave the error log alone.
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('The API call failed: ' . $e->getMessage());
        }

        // getContent() is false for both, so they would read as {data: null}.
        if ($response instanceof BinaryFileResponse) {
            return $this->fileResult($response);
        }
        if ($response instanceof StreamedResponse) {
            return Response::error('This endpoint streams its response, which cannot be relayed over MCP. Use the non-streaming equivalent (e.g. task_log_list).');
        }

        $body = $this->decode($response);
        $status = $response->getStatusCode();

        $payload = ['status' => $status, 'data' => $body];

        // A 4xx/5xx from the API is the tool failing, not the transport: it
        // comes back as a tool error so the model retries or reports rather
        // than reading the error body as a successful result.
        return $status >= 400
            ? Response::error($this->encode($payload))
            : Response::json($payload);
    }

    /**
     * Validation rules derived from the declared parameters. The generated
     * schema() already describes types to the model; this is the enforcement.
     *
     * @return array<string, string>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach ($this->pathParams() as $name) {
            $rules[$this->argument($name)] = 'required';
        }

        // Every parameter the tool forwards needs a rule, not just the ones
        // worth enforcing: Validator::validate() returns only the keys it has
        // rules for, so a query or body parameter without one is dropped and
        // the endpoint is called without it. The API validates the values
        // itself -- these rules exist to keep the arguments, not to check them.
        foreach ([...$this->queryParams(), ...$this->bodyParams()] as $name) {
            $rules[$this->argument($name)] ??= 'sometimes';
        }
        foreach ($this->fileParams() as $param) {
            foreach (UploadArguments::virtualNames($param) as $name) {
                $rules[$name] ??= 'sometimes';
            }
        }

        return $rules;
    }

    /**
     * The validated arguments keyed by the API's parameter names, so the
     * path, query and body declarations can be applied to them as they are.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function apiInput(array $input): array
    {
        $renamed = $this->argumentNames();
        $out = [];

        // Built from the original rather than edited in place: a tool's
        // `name` is the API's `username` while its `new_dbuser` is the API's
        // `name`, and renaming one on top of the other would lose a value.
        foreach ($input as $argument => $value) {
            $out[$renamed[$argument] ?? $argument] = $value;
        }

        return $out + $this->defaults();
    }

    /**
     * @param array<string, mixed> $input Keyed by API parameter name.
     */
    private function dispatch(array $input): SymfonyResponse
    {
        $path = $this->path();

        foreach ($this->pathParams() as $name) {
            $path = str_replace(
                '{' . $name . '}',
                rawurlencode((string)($input[$name] ?? '')),
                $path
            );
        }

        $files = [];
        if ($this->fileParams() !== []) {
            [$input, $files] = UploadArguments::resolve($input, $this->fileParams());
        }

        $query = $this->only($input, $this->queryParams());
        $body = $this->only($input, $this->bodyParams());

        $uri = '/api' . $path;
        if ($query !== []) {
            $uri .= '?' . http_build_query($query);
        }

        // A multipart endpoint gets its fields as form parameters and the
        // files in the files bag, the way a browser would send them; everything
        // else is a JSON body.
        $sub = $files !== []
            ? HttpRequest::create(
                $uri,
                $this->method(),
                $body,
                [],
                $files,
                ['HTTP_ACCEPT' => 'application/json']
            )
            : HttpRequest::create(
                $uri,
                $this->method(),
                [],
                [],
                [],
                ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
                $body === [] ? null : json_encode($body)
            );

        // Same credentials the MCP caller presented; the API re-authorises.
        $bearer = request()?->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $sub->headers->set('Authorization', 'Bearer ' . $bearer);
        }

        $sub->headers->set(self::VIA_HEADER, self::VIA_MCP);

        // The same fact where a caller cannot reach it: the header above is
        // attribution and anyone can set it, while `attributes` is server-side
        // and never populated from an inbound request. `EnsureTokenMayUseApi`
        // reads this to tell a tool call from a curl with an assistant's token.
        $sub->attributes->set(self::VIA_ATTRIBUTE, true);

        // Controllers reach for request() as often as the injected instance, so
        // the binding has to point at the sub-request for the duration and be
        // put back afterwards -- otherwise the MCP request is left clobbered.
        $container = app();
        $original = $container->bound('request') ? $container->make('request') : null;
        $container->instance('request', $sub);

        try {
            return $container->make(Router::class)->dispatch($sub);
        } finally {
            if ($original !== null) {
                $container->instance('request', $original);
            }
            // The endpoint moves the upload into place; whatever it left
            // behind (a rejected file, a failed call) is ours to remove.
            UploadArguments::discard($files);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function only(array $input, array $keys): array
    {
        return array_filter(
            array_intersect_key($input, array_flip($keys)),
            fn (mixed $v): bool => $v !== null
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** A downloaded file, base64-encoded, up to MAX_DOWNLOAD_BYTES. */
    private function fileResult(BinaryFileResponse $response): Response
    {
        $file = $response->getFile();
        $limitMb = self::MAX_DOWNLOAD_BYTES / 1048576;

        // Read through the stream rather than stat(): the path may be sudophp://.
        $handle = @fopen($file->getPathname(), 'rb');
        $bytes = $handle === false ? false : stream_get_contents($handle, self::MAX_DOWNLOAD_BYTES + 1);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($bytes === false) {
            return Response::error("Could not read {$file->getFilename()}.");
        }
        if (strlen($bytes) > self::MAX_DOWNLOAD_BYTES) {
            return Response::error("{$file->getFilename()} is larger than the {$limitMb} MB MCP download limit. Fetch it over the REST API instead.");
        }

        // Content sniffing cannot see through sudophp://, so fall back to the extension.
        $mimeType = explode(';', (string)$response->headers->get('Content-Type'))[0];
        if ($mimeType === '' || $mimeType === 'application/octet-stream') {
            $mimeType = MimeTypes::getDefault()->getMimeTypes($file->getExtension())[0] ?? 'application/octet-stream';
        }

        return Response::json([
            'status' => $response->getStatusCode(),
            'data' => [
                'filename' => $file->getFilename(),
                'mime_type' => $mimeType,
                'size' => strlen($bytes),
                'encoding' => 'base64',
                'content' => base64_encode($bytes),
            ],
        ]);
    }

    private function decode(SymfonyResponse $response): mixed
    {
        $content = $response->getContent();

        if (!is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $content;
    }
}

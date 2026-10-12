<?php

namespace App\Mcp\Tools\Api;

use App\Mcp\Tools\Concerns\OmitsTitle;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
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
    use OmitsTitle;

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

    /**
     * Largest result any tool returns. Tools that are big by nature page
     * well under it (deploy_log_get's max_bytes, DownloadWindow); this is the
     * backstop for the rest -- an ssh_run that cats a log, a huge listing --
     * so they answer with what to narrow instead of a result the client
     * cannot take.
     */
    public const MAX_RESULT_BYTES = 262144;

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
     * Whether the endpoint answers with a file. Such a tool takes the
     * {@see DownloadWindow} arguments and returns the file one window at a time.
     */
    protected function returnsFile(): bool
    {
        return false;
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

    /**
     * A malformed upload argument (an InvalidArgumentException) is the caller's
     * mistake, not an incident: it is answered without being reported.
     */
    public function handle(Request $request): Response
    {
        $input = $this->apiInput($request->validate($this->rules()));

        try {
            $response = $this->dispatch($input);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('The API call failed: ' . $e->getMessage());
        }

        return $this->toolResult($response, $input);
    }

    /**
     * A file or a stream is answered before decoding: getContent() is false for
     * both, so they would read as {data: null}.
     *
     * @param array<string, mixed> $input
     */
    private function toolResult(SymfonyResponse $response, array $input): Response
    {
        if ($response instanceof BinaryFileResponse) {
            return $this->fileResult($response, (int)($input['offset'] ?? 0), (int)($input['length'] ?? DownloadWindow::MAX_BYTES));
        }
        if ($response instanceof StreamedResponse) {
            return Response::error('This endpoint streams its response, which cannot be relayed over MCP. Use the non-streaming equivalent (e.g. task_log_list).');
        }

        return $this->jsonResult($response);
    }

    /**
     * A 4xx/5xx from the API is the tool failing, not the transport: it comes back
     * as a tool error so the model retries or reports rather than reading the error
     * body as a successful result.
     */
    private function jsonResult(SymfonyResponse $response): Response
    {
        $body = $this->decode($response);
        $status = $response->getStatusCode();
        if ($status >= 400 && $status < 500 && is_array($body)) {
            $body = $this->withArgumentNames($body);
        }

        $payload = ['status' => $status, 'data' => $body];

        $size = strlen($this->encode($payload));
        if ($size > self::MAX_RESULT_BYTES) {
            return Response::error(sprintf(
                'The result is %d KB, over the %d KB a tool returns. Ask for less: use the paging or filter '
                . 'arguments this tool has, or for command output pipe it through head, tail or grep.',
                intdiv($size, 1024),
                intdiv(self::MAX_RESULT_BYTES, 1024)
            ));
        }

        return $status >= 400
            ? Response::error($this->encode($payload))
            : Response::json($payload);
    }

    /**
     * An error body names fields as the API knows them; the caller sent the
     * tool's names. Rename `errors` keys, `problems[].field` and the field
     * prefix of `problems[].code` back (`username_invalid` -> `name_invalid`).
     *
     * @param array<mixed> $body
     * @return array<mixed>
     */
    protected function withArgumentNames(array $body): array
    {
        $toTool = array_flip($this->argumentNames());
        if ($toTool === []) {
            return $body;
        }

        if (is_array($body['errors'] ?? null)) {
            $body['errors'] = self::errorsByToolName($body['errors'], $toTool);
        }
        if (is_array($body['problems'] ?? null)) {
            $body['problems'] = self::problemsByToolName($body['problems'], $toTool);
        }

        return $body;
    }

    /**
     * @param array<mixed> $errors
     * @param array<string, string> $toTool
     * @return array<string, mixed>
     */
    private static function errorsByToolName(array $errors, array $toTool): array
    {
        $renamed = [];
        foreach ($errors as $field => $messages) {
            $renamed[self::toolField((string) $field, $toTool)] = $messages;
        }

        return $renamed;
    }

    /**
     * @param array<mixed> $problems
     * @param array<string, string> $toTool
     * @return array<mixed>
     */
    private static function problemsByToolName(array $problems, array $toTool): array
    {
        foreach ($problems as $i => $problem) {
            if (!is_array($problem) || !is_string($problem['field'] ?? null)) {
                continue;
            }
            $from = $problem['field'];
            $to = self::toolField($from, $toTool);
            if ($to === $from) {
                continue;
            }
            $problems[$i]['field'] = $to;
            $prefix = Str::snake($from) . '_';
            if (is_string($problem['code'] ?? null) && str_starts_with($problem['code'], $prefix)) {
                $problems[$i]['code'] = Str::snake($to) . '_' . substr($problem['code'], strlen($prefix));
            }
        }

        return $problems;
    }

    /**
     * An API field (or `field.sub` path) under the tool's argument name.
     *
     * @param array<string, string> $toTool
     */
    private static function toolField(string $field, array $toTool): string
    {
        $head = explode('.', $field, 2);

        return isset($toTool[$head[0]]) ? $toTool[$head[0]] . (isset($head[1]) ? '.' . $head[1] : '') : $field;
    }

    /**
     * Validation rules derived from the declared parameters. The generated
     * schema() already describes types to the model; this is the enforcement.
     * Every forwarded parameter gets a rule, if only `sometimes`: validate()
     * returns only the keys it has rules for, and the API checks the values itself.
     *
     * @return array<string, string>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach ($this->pathParams() as $name) {
            $rules[$this->argument($name)] = 'required';
        }

        foreach ([...$this->queryParams(), ...$this->bodyParams()] as $name) {
            $rules[$this->argument($name)] ??= 'sometimes';
        }
        foreach ($this->fileParams() as $param) {
            foreach (UploadArguments::virtualNames($param) as $name) {
                $rules[$name] ??= 'sometimes';
            }
        }
        if ($this->returnsFile()) {
            $rules = DownloadWindow::rules() + $rules;
        }

        return $rules;
    }

    /**
     * The validated arguments keyed by the API's parameter names, so the
     * path, query and body declarations can be applied to them as they are.
     * Built from the original rather than edited in place: a tool's `name` is
     * the API's `username` while its `new_dbuser` is the API's `name`.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function apiInput(array $input): array
    {
        $renamed = $this->argumentNames();
        $out = [];

        foreach ($input as $argument => $value) {
            $out[$renamed[$argument] ?? $argument] = $value;
        }

        $defaults = $this->defaults();
        if ($defaults !== []) {
            $out = $this->withDefaultsForOmitted($out, $defaults);
        }

        return $out + $defaults;
    }

    /**
     * A null the schema does not admit is an omitted argument, so the default applies.
     * Where the schema admits null, null has a meaning of its own and is kept.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $defaults
     * @return array<string, mixed>
     */
    private function withDefaultsForOmitted(array $input, array $defaults): array
    {
        $nullable = array_flip($this->nullableParams());
        foreach ($defaults as $name => $default) {
            if (($input[$name] ?? null) === null && !isset($nullable[$name])) {
                $input[$name] = $default;
            }
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input Keyed by API parameter name.
     */
    private function dispatch(array $input): SymfonyResponse
    {
        $path = $this->substitutedPath($input);

        $files = [];
        if ($this->fileParams() !== []) {
            [$input, $files] = UploadArguments::resolve($input, $this->fileParams());
        }

        $sub = $this->subRequest($path, $input, $files);
        $this->markAsToolCall($sub);

        return $this->dispatchAsTheRequest($sub, $files);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function substitutedPath(array $input): string
    {
        $path = $this->path();

        foreach ($this->pathParams() as $name) {
            $path = str_replace(
                '{' . $name . '}',
                rawurlencode((string)($input[$name] ?? '')),
                $path
            );
        }

        return $path;
    }

    /**
     * A multipart endpoint gets its fields as form parameters and the files in the
     * files bag, as a browser sends them; everything else is a JSON body, which is
     * put in the `request` bag too, as Request::createFromBase() does for a real one.
     * REMOTE_ADDR is the caller's, not 127.0.0.1: the firewall checks a deny against it.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     */
    private function subRequest(string $path, array $input, array $files): HttpRequest
    {
        $query = $this->only($input, $this->queryParams());
        $body = $this->only($input, $this->bodyParams());

        $uri = '/api' . $path;
        if ($query !== []) {
            $uri .= '?' . http_build_query($query);
        }

        $sub = $this->createRequest($uri, $body, $files);

        if ($files === [] && $body !== []) {
            $sub->request->replace($body);
        }

        return $sub;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     */
    private function createRequest(string $uri, array $body, array $files): HttpRequest
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => request()?->ip() ?? '127.0.0.1'];

        if ($files !== []) {
            return HttpRequest::create($uri, $this->method(), $body, [], $files, $server);
        }

        return HttpRequest::create(
            $uri,
            $this->method(),
            [],
            [],
            [],
            $server + ['CONTENT_TYPE' => 'application/json'],
            $body === [] ? null : json_encode($body)
        );
    }

    /**
     * Forwards the MCP caller's credentials, so the API re-authorises. The via header
     * is attribution anyone can set; the attribute is server-side and never filled from
     * an inbound request, which is how `EnsureTokenMayUseApi` tells a tool call from a
     * curl with an assistant's token.
     */
    private function markAsToolCall(HttpRequest $sub): void
    {
        $bearer = request()?->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $sub->headers->set('Authorization', 'Bearer ' . $bearer);
        }

        $sub->headers->set(self::VIA_HEADER, self::VIA_MCP);
        $sub->attributes->set(self::VIA_ATTRIBUTE, true);
    }

    /**
     * Controllers reach for request() as often as the injected instance, so the binding
     * points at the sub-request for the duration and is put back afterwards. Whatever
     * upload the endpoint left behind (a rejected file, a failed call) is removed.
     *
     * @param array<string, mixed> $files
     */
    private function dispatchAsTheRequest(HttpRequest $sub, array $files): SymfonyResponse
    {
        $container = app();
        $original = $container->bound('request') ? $container->make('request') : null;
        $container->instance('request', $sub);

        try {
            return $container->make(Router::class)->dispatch($sub);
        } finally {
            if ($original !== null) {
                $container->instance('request', $original);
            }
            UploadArguments::discard($files);
        }
    }

    /**
     * A null is dropped like an omitted argument, unless the schema admits null
     * for that argument: there the API gives null a meaning of its own.
     *
     * @param array<string, mixed> $input
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function only(array $input, array $keys): array
    {
        $nullable = array_flip($this->nullableParams());

        return array_filter(
            array_intersect_key($input, array_flip($keys)),
            fn (mixed $v, int|string $k): bool => $v !== null || isset($nullable[$k]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * API names of the parameters whose schema admits null.
     *
     * @return array<int, string>
     */
    private function nullableParams(): array
    {
        $properties = JsonSchemaFactory::object($this->schema(...))->toArray()['properties'] ?? [];
        $renamed = $this->argumentNames();
        $names = [];
        foreach ((array)$properties as $argument => $definition) {
            if (in_array('null', (array)($definition['type'] ?? []), true)) {
                $names[] = $renamed[$argument] ?? $argument;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** One {@see DownloadWindow} of a downloaded file. */
    private function fileResult(BinaryFileResponse $response, int $offset, int $length): Response
    {
        $file = $response->getFile();

        $window = self::readWindow($file->getPathname(), $offset, $length);
        if ($window === null) {
            return Response::error("Could not read {$file->getFilename()}.");
        }

        $size = @filesize($file->getPathname());

        return Response::json([
            'status' => $response->getStatusCode(),
            'data' => [
                'filename' => $file->getFilename(),
                'mime_type' => self::mimeTypeOf($response, $file),
                'size' => $size === false ? null : $size,
            ] + $window,
        ]);
    }

    /**
     * Read through the stream rather than stat(): the path may be sudophp://.
     *
     * @return ?array<string, mixed>
     */
    private static function readWindow(string $path, int $offset, int $length): ?array
    {
        $handle = @fopen($path, 'rb');
        $window = $handle === false ? null : DownloadWindow::read($handle, max(0, $offset), $length);
        if ($handle !== false) {
            fclose($handle);
        }

        return $window;
    }

    /** Content sniffing cannot see through sudophp://, so an unspecific type falls back to the extension. */
    private static function mimeTypeOf(BinaryFileResponse $response, \SplFileInfo $file): string
    {
        $mimeType = explode(';', (string)$response->headers->get('Content-Type'))[0];
        if ($mimeType === '' || $mimeType === 'application/octet-stream') {
            $mimeType = MimeTypes::getDefault()->getMimeTypes($file->getExtension())[0] ?? 'application/octet-stream';
        }

        return $mimeType;
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

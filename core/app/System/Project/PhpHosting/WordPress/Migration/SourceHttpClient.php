<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use Closure;

/**
 * Cookie-aware HTTP transport for the source WordPress site.
 * Cookies stay in process memory for the life of the client instance.
 */
interface SourceHttpClient
{
    /**
     * @param  array<string, string>  $headers
     */
    public function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
    ): SourceHttpResponse;

    /**
     * Multipart form post (plugin zip upload).
     *
     * @param  array<string, string|\CURLFile>  $fields
     */
    public function postMultipart(string $url, array $fields): SourceHttpResponse;

    /**
     * Ask $keepWaiting before every request and while one waits on the source;
     * a false answer aborts it with {@see SourceRequestAborted}. Null stops asking.
     *
     * @param  (Closure(): bool)|null  $keepWaiting
     */
    public function whileWaiting(?Closure $keepWaiting): void;
}

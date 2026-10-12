<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

trait StreamsNdjson
{
    /**
     * A 200 NDJSON response no proxy buffers, whose body runs to the end even after the client leaves.
     *
     * @param array<string, string> $headers added to the stream's own
     */
    protected static function ndjsonResponse(callable $body, array $headers = []): StreamedResponse
    {
        ignore_user_abort(true);
        set_time_limit(0);

        return response()->stream($body, 200, [
            'Content-Type' => 'application/x-ndjson',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
            ...$headers,
        ]);
    }

    /**
     * Drains the FPM/proxy buffers so each NDJSON line flushes. Not under PHPUnit, where
     * TestResponse::streamedContent() owns a buffer and wiping it fails the capture.
     */
    protected static function dropOutputBuffers(): void
    {
        if (!app()->runningUnitTests()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
    }
}

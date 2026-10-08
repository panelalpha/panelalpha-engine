<?php

namespace Tests\Unit\Files;

use App\Lib\Helpers\FileStreamWrapper;
use PHPUnit\Framework\TestCase;

/**
 * PHP assigns `$context` on every stream wrapper instance. Undeclared, that
 * is a dynamic property: four deprecation notices per file download on 8.4.
 */
class FileStreamWrapperContextTest extends TestCase
{
    public function test_opening_a_stream_with_a_context_raises_no_deprecation(): void
    {
        stream_wrapper_register('pa-ctx-probe', ContextProbeWrapper::class);
        $deprecations = [];
        set_error_handler(function (int $errno, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED);

        try {
            $handle = fopen('pa-ctx-probe://x', 'r', false, stream_context_create());
            $this->assertIsResource($handle);
            fclose($handle);
        } finally {
            restore_error_handler();
            stream_wrapper_unregister('pa-ctx-probe');
        }

        $this->assertSame([], $deprecations);
    }
}

/** The real wrapper opens through `sudo php`; only the property matters here. */
class ContextProbeWrapper extends FileStreamWrapper
{
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}

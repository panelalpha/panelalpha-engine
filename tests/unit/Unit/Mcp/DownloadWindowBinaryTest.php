<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\DownloadWindow;
use PHPUnit\Framework\TestCase;

class DownloadWindowBinaryTest extends TestCase
{
    public function test_bytes_that_are_not_text_come_back_as_base64(): void
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, "\xff\xfe\x01binary");
        rewind($handle);

        $window = DownloadWindow::read($handle, 0, 100);

        $this->assertSame('base64', $window['encoding']);
        $this->assertSame("\xff\xfe\x01binary", base64_decode($window['content']));
        $this->assertFalse($window['more']);
        $this->assertNull($window['next_offset']);
    }

    public function test_a_stream_that_cannot_be_positioned_has_no_window_past_its_start(): void
    {
        stream_wrapper_register('pa-unseekable', UnseekableStream::class);

        try {
            $handle = fopen('pa-unseekable://file', 'r');
            $this->assertNull(DownloadWindow::read($handle, 10, 100));
        } finally {
            stream_wrapper_unregister('pa-unseekable');
        }
    }
}

/** A readable stream that refuses every seek. */
final class UnseekableStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }
}

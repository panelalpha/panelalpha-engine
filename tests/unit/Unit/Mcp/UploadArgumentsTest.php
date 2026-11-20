<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\UploadArguments;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\UploadArgumentsFixtures;

/**
 * A tool call is JSON, so a multipart file field has to arrive as something
 * else: base64 or text contents, or a URL the engine downloads. These pin
 * down that translation and the ways it refuses to guess.
 */
class UploadArgumentsTest extends TestCase
{
    use UploadArgumentsFixtures;

    public function test_the_four_virtual_arguments_stand_in_for_one_binary_field(): void
    {
        $this->assertSame(
            ['file_name', 'file_contents', 'file_encoding', 'file_url'],
            UploadArguments::virtualNames('file')
        );
        $this->assertSame(
            ['file_name', 'file_contents', 'file_encoding', 'file_url'],
            array_map(fn (array $d): string => $d[0], UploadArguments::describe('file'))
        );
    }

    public function test_contents_and_url_are_exactly_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one of file_contents or file_url');

        UploadArguments::resolve(['file_name' => 'a.zip'], ['file']);
    }

    public function test_both_contents_and_url_is_refused_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UploadArguments::resolve(
            ['file_name' => 'a.zip', 'file_contents' => 'YQ==', 'file_url' => 'https://example.com/a.zip'],
            ['file']
        );
    }
}

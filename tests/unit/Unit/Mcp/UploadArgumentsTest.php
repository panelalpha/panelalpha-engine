<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\UploadArguments;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * A tool call is JSON, so a multipart file field has to arrive as something
 * else: base64 or text contents, or a URL the engine downloads. These pin
 * down that translation and the ways it refuses to guess.
 */
class UploadArgumentsTest extends TestCase
{
    /** @var array<int, string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

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

    public function test_base64_contents_become_a_file_and_leave_only_the_endpoint_fields(): void
    {
        [$input, $files] = UploadArguments::resolve([
            'username' => 'alice',
            'path' => '/project',
            'file_name' => 'app.zip',
            'file_contents' => base64_encode("PK\x03\x04zip"),
        ], ['file']);

        $this->assertSame(['username' => 'alice', 'path' => '/project'], $input);
        $this->assertArrayHasKey('file', $files);
        $this->cleanup[] = $files['file']->getPathname();
        $this->assertSame('app.zip', $files['file']->getClientOriginalName());
        $this->assertSame("PK\x03\x04zip", file_get_contents($files['file']->getPathname()));
        $this->assertTrue($files['file']->isValid(), 'test-mode upload passes the file validation rule');
    }

    public function test_text_encoding_writes_the_contents_verbatim(): void
    {
        [, $files] = UploadArguments::resolve([
            'file_name' => 'index.html',
            'file_contents' => '<h1>hi</h1>',
            'file_encoding' => 'text',
        ], ['file']);
        $this->cleanup[] = $files['file']->getPathname();

        $this->assertSame('<h1>hi</h1>', file_get_contents($files['file']->getPathname()));
    }

    public function test_a_url_is_downloaded_and_named_after_its_last_segment(): void
    {
        $fetched = [];
        [, $files] = UploadArguments::resolve(
            ['file_url' => 'https://example.com/releases/v1.2/app%20one.tar.gz?x=1'],
            ['file'],
            function (string $url, string $into) use (&$fetched): void {
                $fetched[] = $url;
                file_put_contents($into, 'tarball');
            }
        );
        $this->cleanup[] = $files['file']->getPathname();

        $this->assertSame(['https://example.com/releases/v1.2/app%20one.tar.gz?x=1'], $fetched);
        $this->assertSame('app one.tar.gz', $files['file']->getClientOriginalName());
        $this->assertSame('tarball', file_get_contents($files['file']->getPathname()));
    }

    public function test_an_explicit_name_wins_over_the_url(): void
    {
        [, $files] = UploadArguments::resolve(
            ['file_url' => 'https://example.com/archive/refs/heads/main.zip', 'file_name' => 'site.zip'],
            ['file'],
            fn (string $url, string $into) => file_put_contents($into, 'z')
        );
        $this->cleanup[] = $files['file']->getPathname();

        $this->assertSame('site.zip', $files['file']->getClientOriginalName());
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

    public function test_invalid_base64_is_named_as_such(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid base64');

        UploadArguments::resolve(['file_name' => 'a.txt', 'file_contents' => 'not base64!'], ['file']);
    }

    public function test_only_http_and_https_urls_are_fetched(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('http:// or https://');

        UploadArguments::resolve(['file_url' => 'file:///etc/passwd'], ['file'], fn () => null);
    }

    /**
     * The name becomes part of a path inside the account; a directory
     * component would let an upload land somewhere the caller did not name.
     */
    public function test_a_name_with_a_directory_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('plain file name');

        UploadArguments::resolve(['file_name' => '../escape.zip', 'file_contents' => 'YQ=='], ['file']);
    }

    public function test_a_refused_upload_leaves_no_temporary_file_behind(): void
    {
        $before = count((array) glob(sys_get_temp_dir() . '/pa-mcp-upload-*'));
        try {
            UploadArguments::resolve(['file_name' => 'x/y', 'file_contents' => 'YQ=='], ['file']);
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($before, count((array) glob(sys_get_temp_dir() . '/pa-mcp-upload-*')));
    }
}

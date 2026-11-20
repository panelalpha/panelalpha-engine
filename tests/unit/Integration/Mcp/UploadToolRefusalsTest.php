<?php

namespace Tests\Integration\Mcp;

use App\Mcp\Tools\Api\UploadArguments;
use App\Mcp\Tools\Api\Uploads\UploadCreateTool;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Tests\TestCase;

/** An upload argument the tool cannot turn into a file is answered as the caller's mistake, and nothing is left behind. */
class UploadToolRefusalsTest extends TestCase
{
    public function test_an_upload_with_neither_contents_nor_url_is_answered_as_an_error(): void
    {
        $response = (new UploadCreateTool())->handle(new Request(['file_name' => 'app.zip']));

        $this->assertTrue($response->isError());
        $this->assertSame('Pass exactly one of file_contents or file_url.', (string) $response->content());
    }

    public function test_an_unknown_encoding_is_refused_and_its_temporary_file_removed(): void
    {
        $before = glob(sys_get_temp_dir() . '/pa-mcp-upload-*') ?: [];

        try {
            UploadArguments::resolve(['file_name' => 'a.txt', 'file_contents' => 'abc', 'file_encoding' => 'hex'], ['file']);
            $this->fail('An unknown encoding was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('file_encoding must be base64 or text.', $e->getMessage());
        }

        $this->assertSame($before, glob(sys_get_temp_dir() . '/pa-mcp-upload-*') ?: []);
    }

    public function test_discarding_resolved_uploads_removes_their_files(): void
    {
        [, $files] = UploadArguments::resolve(['file_name' => 'a.txt', 'file_contents' => 'abc', 'file_encoding' => 'text'], ['file']);
        $path = $files['file']->getPathname();
        $this->assertFileExists($path);

        UploadArguments::discard($files);
        UploadArguments::discard($files);

        $this->assertFileDoesNotExist($path);
    }
}

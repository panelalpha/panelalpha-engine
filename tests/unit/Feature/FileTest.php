<?php

namespace Tests\Feature;

use App\System;
use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class FileTest extends TestCase
{
    private static string $testDirPath = '/test-dir';
    private static string $testFileName = 'test-file.txt';

    public function test_file_exists(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));
        $this->authenticate();

        $system = new System();
        $filePath = $system->projectHomeDirPath($username) . "/test_" . Str::random(4) . ".txt";

        $response = $this->getJson("/api/users/{$username}/files/exists?path={$filePath}");
        $response->assertStatus(200);
        $this->assertEquals(false, $response->json('exists'));

        $system->filesystem()->filePutContents($filePath, "test");

        $response = $this->getJson("/api/users/{$username}/files/exists?path={$filePath}");
        $response->assertStatus(200);

        $system->runProcess(["sudo", "rm", $filePath]);
    }

    public function test_create_directory(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));
        $this->authenticate();

        $payload = ['path' => self::$testDirPath, 'parents' => true];
        $response = $this->postJson("/api/users/{$username}/files/mkdir", $payload);
        $response->assertStatus(200);
    }

    public function test_upload_file(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));
        $this->authenticate();

        $file = \Illuminate\Http\UploadedFile::fake()->create(self::$testFileName, 100);
        $payload = ['path' => self::$testDirPath, 'file' => $file];

        $response = $this->postJson("/api/users/{$username}/files/upload", $payload);
        $response->assertStatus(200);
    }

    public function test_stat_file(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));
        $this->authenticate();

        $path = self::$testDirPath . '/' . self::$testFileName;
        $response = $this->getJson("/api/users/{$username}/files/stat?path=" . $path);
        $response->assertStatus(200);
    }

    public function test_delete_file(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));
        $this->authenticate();

        $path = self::$testDirPath . '/' . self::$testFileName;
        $payload = ['path' => $path, 'recursive' => false];
        $response = $this->deleteJson("/api/users/{$username}/files/remove", $payload);
        $response->assertStatus(200);
    }

    public function test_download_file(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));
        $this->authenticate();

        $system = new System();
        $filePath = $system->projectHomeDirPath($username) . "/test_" . Str::random(4) . ".txt";

        $contents = [
            "plain text",
            "こんにちは世界 🌏",
            random_bytes(16),
        ];

        foreach ($contents as $content) {
            $system->filesystem()->filePutContents($filePath, $content);

            // Full download test
            $response = $this->get("/api/users/{$username}/files/download?path={$filePath}");
            ob_start();
            $response->sendContent();
            $downloaded = ob_get_clean();
            $this->assertSame($content, $downloaded);

            // Partial download (first 5 bytes)
            $rangeHeader = ['Range' => 'bytes=0-4'];
            $response = $this->get("/api/users/{$username}/files/download?path={$filePath}", $rangeHeader);
            ob_start();
            $response->sendContent();
            $partial = ob_get_clean();

            $this->assertSame(substr($content, 0, 5), $partial, 'Partial content mismatch');
            $this->assertEquals(206, $response->getStatusCode(), 'Expected 206 Partial Content');
            $this->assertStringContainsString(
                'bytes 0-4/',
                $response->headers->get('Content-Range') ?? '',
                'Missing or incorrect Content-Range header'
            );
        }

        $system->runProcess(["sudo", "rm", $filePath]);
    }
}

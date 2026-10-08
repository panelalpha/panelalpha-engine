<?php

namespace Tests\Unit\Files;

use App\Http\Requests\Files\ChmodRequest;
use App\Http\Requests\Files\FetchRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class FileBoundaryRequestTest extends TestCase
{
    public function test_fetch_accepts_http_and_https_only(): void
    {
        $this->assertFalse($this->fails(FetchRequest::class, [
            'url' => 'https://example.com/plugin.zip',
            'path' => 'inbox',
        ]));
        $this->assertFalse($this->fails(FetchRequest::class, [
            'url' => 'http://example.com/plugin.zip',
            'path' => 'inbox',
            'filename' => 'plugin.zip',
        ]));

        $this->assertTrue($this->fails(FetchRequest::class, [
            'url' => 'file:///etc/passwd',
            'path' => 'inbox',
            'filename' => 'stolen.txt',
        ]));
        $this->assertTrue($this->fails(FetchRequest::class, [
            'url' => 'ftp://example.com/plugin.zip',
            'path' => 'inbox',
        ]));
    }

    public function test_chmod_accepts_three_or_four_octal_digits(): void
    {
        $this->assertFalse($this->fails(ChmodRequest::class, [
            'path' => 'script.sh',
            'mode' => '755',
        ]));
        $this->assertFalse($this->fails(ChmodRequest::class, [
            'path' => 'script.sh',
            'mode' => '0644',
        ]));

        $this->assertTrue($this->fails(ChmodRequest::class, [
            'path' => 'script.sh',
            'mode' => '999',
        ]));
        $this->assertTrue($this->fails(ChmodRequest::class, [
            'path' => 'script.sh',
            'mode' => '77',
        ]));
        $this->assertTrue($this->fails(ChmodRequest::class, [
            'path' => 'script.sh',
            'mode' => '07555',
        ]));
    }

    /**
     * @param class-string<FetchRequest|ChmodRequest> $requestClass
     * @param array<string, mixed> $payload
     */
    private function fails(string $requestClass, array $payload): bool
    {
        $request = new $requestClass();

        return Validator::make($payload, $request->rules())->fails();
    }
}

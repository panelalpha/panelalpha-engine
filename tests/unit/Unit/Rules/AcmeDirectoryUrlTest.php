<?php

namespace Tests\Unit\Rules;

use App\Rules\AcmeDirectoryUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AcmeDirectoryUrlTest extends TestCase
{
    /** @return array<string, array{mixed}> */
    public static function accepted(): array
    {
        return [
            'null means the default' => [null],
            'empty means the default' => [''],
            'blank means the default' => ['   '],
            'live shorthand' => ['live'],
            'staging shorthand in capitals' => ['STAGING'],
            'an https directory' => ['https://acme.example.net/directory'],
            'an http directory' => ['http://acme.example.net:14000/dir'],
        ];
    }

    /** @return array<string, array{mixed}> */
    public static function refused(): array
    {
        return [
            'a bare word that is no shorthand' => ['letsencrypt'],
            'an ftp URL' => ['ftp://acme.example.net/directory'],
            'a file URL' => ['file:///etc/passwd'],
            'a host without a scheme' => ['acme.example.net/directory'],
            'a scheme with no host' => ['https://'],
            'a number' => [42],
        ];
    }

    #[DataProvider('accepted')]
    public function test_operator_setting_an_acme_directory_is_accepted(mixed $value): void
    {
        $this->assertNull($this->failure($value));
    }

    #[DataProvider('refused')]
    public function test_operator_setting_an_unusable_acme_directory_is_refused(mixed $value): void
    {
        $message = $this->failure($value);

        $this->assertNotNull($message);
        $this->assertStringContainsString('http or https URL', $message);
        $this->assertStringContainsString('staging', $message);
    }

    private function failure(mixed $value): ?string
    {
        $message = null;
        (new AcmeDirectoryUrl)->validate('acme_directory_url', $value, function (string $text) use (&$message): void {
            $message = $text;
        });

        return $message;
    }
}

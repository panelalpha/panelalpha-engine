<?php

namespace Tests\Unit\Console;

use App\Console\Wizard\Sections\EngineAddressSection;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The two fields in this section that are worth refusing bad input for.
 *
 * `cert_domain` is quietly load-bearing — besides the certificate it names
 * every project site created from then on — so a typo does not fail loudly.
 * It sends new sites somewhere nobody expects and then fails a certificate
 * request for a name that was never real. Proved on a live engine by leaving
 * a stray "q" in it.
 */
class EngineAddressSectionTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function names(): array
    {
        return [
            'empty means the default' => ['', true],
            'a hostname' => ['panel.example.com', true],
            'a dashed panelalpha.direct name' => ['178-104-84-45.panelalpha.direct', true],
            'one label is not a hostname' => ['q', false],
            'still not, with more letters' => ['localhost', false],
            'no leading dot' => ['.example.com', false],
            'no trailing dot' => ['example.com.', false],
            'no spaces' => ['panel example.com', false],
            'no scheme' => ['https://panel.example.com', false],
            'no underscores' => ['panel_1.example.com', false],
            'no leading hyphen in a label' => ['-panel.example.com', false],
        ];
    }

    #[DataProvider('names')]
    public function test_it_takes_a_hostname_or_nothing(string $name, bool $acceptable): void
    {
        $this->assertSame($acceptable, $this->check('badName', $name) === null, $name);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function addresses(): array
    {
        return [
            'empty is allowed — the prompt keeps the current one' => ['', true],
            'https with a port' => ['https://panel.example.com:2011', true],
            'https without a port' => ['https://panel.example.com', true],
            'a bare hostname is not an address' => ['panel.example.com', false],
            'nor is a path' => ['/mcp', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_the_address_has_to_be_a_url(string $url, bool $acceptable): void
    {
        $this->assertSame($acceptable, $this->check('badUrl', $url) === null, $url);
    }

    private function check(string $method, string $value): ?string
    {
        $check = new ReflectionMethod(EngineAddressSection::class, $method);

        return $check->invoke(new EngineAddressSection(), $value);
    }
}

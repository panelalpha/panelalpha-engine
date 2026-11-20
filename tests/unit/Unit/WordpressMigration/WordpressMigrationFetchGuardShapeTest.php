<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A source URL that cannot be parsed, has no host, or whose host resolves to nothing usable is refused before any fetch.
 */
class WordpressMigrationFetchGuardShapeTest extends TestCase
{
    public function test_a_url_that_cannot_be_parsed_is_invalid(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source WordPress URL is invalid');

        SourceFetchGuard::assertShape('http://:80');
    }

    public function test_a_url_without_a_host_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source WordPress URL is missing a host');

        SourceFetchGuard::assertShape('https:/wp-admin');
    }

    public function test_the_host_comes_back_without_ipv6_brackets(): void
    {
        $this->assertSame('::1', SourceFetchGuard::assertShape('https://[::1]:8443/blog'));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function unusableAnswers(): iterable
    {
        yield 'no address' => [[]];
        yield 'not a list' => [false];
        yield 'only empty values' => [['', null]];
    }

    #[DataProvider('unusableAnswers')]
    public function test_a_host_that_resolves_to_nothing_usable_is_refused(mixed $answer): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source WordPress host did not resolve');

        SourceFetchGuard::assertAllowed('https://shop.example.com/', new SourceIpPolicy, static fn (): mixed => $answer);
    }
}

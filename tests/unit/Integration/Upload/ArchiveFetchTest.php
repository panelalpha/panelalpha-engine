<?php

namespace Tests\Integration\Upload;

use App\Lib\Http\OutboundUrl;
use App\Lib\Project\ArchiveDrop;
use App\Lib\Upload\ArchiveFetch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * An archive named by URL is put into the project under the URL's last segment, so the deploy names that file; the
 * format its first bytes say is added when the segment does not say it.
 */
class ArchiveFetchTest extends TestCase
{
    private const ZIP = "PK\x03\x04archive";

    private const GZIP = "\x1f\x8barchive";

    /** @var \ArrayObject<int, string> the names the archives were put in by */
    private \ArrayObject $names;

    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(4)]);
        $this->names = new \ArrayObject();
        $this->app->instance(ArchiveDrop::class, new class ($this->names) extends ArchiveDrop {
            /** @param \ArrayObject<int, string> $names */
            public function __construct(private \ArrayObject $names)
            {
            }

            public function put(User $user, string $file, string $name, string $dir = self::TARGET_DIR): string
            {
                $this->names[] = $name;

                return $dir . '/.deploy-archive-test/' . $name;
            }
        });
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function urls(): iterable
    {
        yield 'a release asset' => ['https://downloads.example.com/releases/site%20v2.zip', self::ZIP, 'site v2.zip'];
        yield 'a tgz' => ['https://downloads.example.com/release.tgz', self::GZIP, 'release.tgz'];
        yield 'a branch archive with no suffix' => ['https://downloads.example.com/archive/refs/heads/main', self::GZIP, 'main.tar.gz'];
        yield 'a query, not part of the name' => ['https://downloads.example.com/get?id=3&name=x.zip', self::ZIP, 'get.zip'];
        yield 'a suffix the bytes contradict' => ['https://downloads.example.com/app.tar.gz', self::ZIP, 'app.tar.gz.zip'];
        yield 'no path' => ['https://downloads.example.com/', self::ZIP, 'archive.zip'];
        yield 'a segment too long for a file name' => ['https://downloads.example.com/' . str_repeat('a', 201) . '.zip', self::ZIP, 'archive.zip'];
    }

    #[DataProvider('urls')]
    public function test_the_archive_is_named_by_the_urls_last_segment(string $url, string $body, string $name): void
    {
        Http::fake(['*' => Http::response($body)]);
        $user = new User();
        $user->username = 'alice';

        $zipPath = (new ArchiveFetch())->into($user, $url);

        $this->assertSame([$name], $this->names->getArrayCopy());
        $this->assertSame('/project/.deploy-archive-test/' . $name, $zipPath);
    }
}

<?php

namespace Tests\Integration\Project;

use App\Exceptions\ProblemException;
use App\Lib\Helpers\FileStreamWrapper;
use App\Lib\Project\FileThumbnail;
use App\Lib\Project\ProjectFiles;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Services\Webserver;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use ReflectionProperty;
use Tests\TestCase;

/**
 * What a thumbnail decides before any pixel is decoded: its ETag from the account's own stat, nothing for a file the
 * account has not, and a refusal for a file too large or not an image. The confined stream is replaced by one that
 * serves bytes from memory, so no helper process runs.
 */
class FileThumbnailSourceTest extends TestCase
{
    private const PATH = '/home/shop/public_html/photo.png';

    private FileManager&MockInterface $files;

    /** The bytes the stand-in stream serves, by path; a path not here fails to open. */
    public static array $served = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$served = [];
        $this->files = Mockery::mock(FileManager::class);
        $this->files->allows('homeDirPath')->andReturn('/home/shop');
        $this->files->allows('accountIds')->andReturn([1001, 1001]);
        if (in_array('rootfile', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('rootfile');
        }
        stream_wrapper_register('rootfile', get_class(new class {
            public $context;

            private string $bytes = '';

            private int $at = 0;

            public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
            {
                $key = substr($path, strlen('rootfile://'));
                if (! array_key_exists($key, FileThumbnailSourceTest::$served)) {
                    return false;
                }
                $this->bytes = FileThumbnailSourceTest::$served[$key];

                return true;
            }

            public function stream_read(int $count): string
            {
                $chunk = substr($this->bytes, $this->at, $count);
                $this->at += strlen($chunk);

                return $chunk;
            }

            public function stream_eof(): bool
            {
                return $this->at >= strlen($this->bytes);
            }

            public function stream_stat(): array
            {
                return ['size' => strlen($this->bytes)];
            }

            public function stream_seek(int $offset, int $whence = SEEK_SET): bool
            {
                $this->at = $offset;

                return true;
            }
        }));
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('rootfile');
        FileStreamWrapper::unconfine();
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        parent::tearDown();
    }

    public function test_the_etag_follows_the_file_and_the_thumbnail_asked_for(): void
    {
        $this->insideHome(true);
        $this->files->allows('stat')->with(self::PATH)->andReturn(['path' => self::PATH, 'mtime' => 1700000000, 'size' => 2048]);
        $thumbnail = new FileThumbnail;

        $etag = $thumbnail->etag($this->user(), self::PATH, 200, 200, 'cover');

        $this->assertSame(sha1(implode("\n", [self::PATH, 1700000000, 2048, 200, 200, 'cover'])), $etag);
        $this->assertNotSame($etag, $thumbnail->etag($this->user(), self::PATH, 200, 200, 'contain'));
        $this->assertNotSame($etag, $thumbnail->etag($this->user(), self::PATH, 100, 200, 'cover'));
    }

    public function test_a_file_outside_the_home_has_no_thumbnail(): void
    {
        $this->insideHome(false);
        $this->files->shouldNotReceive('stat');

        $this->assertNull((new FileThumbnail)->etag($this->user(), self::PATH, 200, 200, 'cover'));
        $this->assertNull((new FileThumbnail)->render($this->user(), self::PATH, 200, 200, 'cover'));
    }

    public function test_a_source_over_the_size_limit_is_refused_from_its_stat(): void
    {
        $this->insideHome(true);
        $this->files->allows('stat')->andReturn(['path' => self::PATH, 'mtime' => 1, 'size' => FileThumbnail::MAX_SOURCE_BYTES + 1]);

        $this->assertSame('image_too_large', $this->problemOf(fn () => (new FileThumbnail)->etag($this->user(), self::PATH, 200, 200, 'cover'))['code']);
        $this->assertSame('image_too_large', $this->problemOf(fn () => (new FileThumbnail)->render($this->user(), self::PATH, 200, 200, 'cover'))['code']);
    }

    public function test_a_file_the_account_cannot_open_has_no_thumbnail(): void
    {
        $this->insideHome(true);
        $this->files->allows('stat')->andReturn(['path' => self::PATH, 'mtime' => 1, 'size' => 10]);

        $this->assertNull(@(new FileThumbnail)->render($this->user(), self::PATH, 200, 200, 'cover'));
    }

    /** @return iterable<string, array{string}> */
    public static function notImages(): iterable
    {
        yield 'text' => ["this is not an image\n"];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'];
        yield 'a riff that is not webp' => ['RIFF'."\x00\x00\x00\x00".'WAVEfmt '];
        yield 'a truncated png signature' => ["\x89PNG\r\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notImages')]
    public function test_bytes_that_are_not_a_supported_image_are_refused_by_their_magic(string $bytes): void
    {
        $this->insideHome(true);
        $this->files->allows('stat')->andReturn(['path' => self::PATH, 'mtime' => 1, 'size' => strlen($bytes)]);
        self::$served[self::PATH] = $bytes;

        $problem = $this->problemOf(fn () => (new FileThumbnail)->render($this->user(), self::PATH, 200, 200, 'cover'));

        $this->assertSame(['path', 'unsupported_image'], [$problem['field'], $problem['code']]);
    }

    public function test_an_htaccess_upload_on_nginx_needs_no_reload(): void
    {
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');
        $file = UploadedFile::fake()->create('.htaccess', 1);
        $this->files->expects('moveUploadedFile')->with('/home/shop/public_html', $file);

        ProjectFiles::upload($this->user(), '/home/shop/public_html', $file);
    }

    private function insideHome(bool $inside): void
    {
        $this->files->allows('isInsideHomeFile')->with(self::PATH)->andReturn($inside);
    }

    private function user(): User
    {
        $project = Mockery::mock(Project::class);
        $project->allows('fileManager')->andReturn($this->files);
        $user = new class ($project) extends User {
            public function __construct(private ?Project $fake = null)
            {
                parent::__construct();
            }

            public function project(?System $system = null): Project
            {
                return $this->fake ?? parent::project($system);
            }
        };
        $user->username = 'shop';

        return $user;
    }

    /** @return array<string, mixed> */
    private function problemOf(\Closure $call): array
    {
        try {
            $call();
        } catch (ProblemException $e) {
            return $e->problems[0];
        }
        $this->fail('expected a ProblemException');
    }
}

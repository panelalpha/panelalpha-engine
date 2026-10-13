<?php

namespace Tests\Unit\Files;

use App\Http\Middleware\Authenticate;
use App\Lib\Helpers\FileStreamWrapper;
use App\Lib\Project\FileThumbnail;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Imagick;
use Tests\TestCase;

/**
 * The thumbnail endpoint end to end: the real confined stream reads the file
 * as the account, and Imagick resizes it. Only `sudo` is shimmed to run its
 * command as is; setpriv then really drops to uid 1001, so a read of a path
 * the account cannot see fails for the right reason.
 *
 * Needs root, util-linux setpriv and imagick (composer:2 has neither): run it
 * in the engine-core image, or it skips.
 */
class FileThumbnailHttpTest extends TestCase
{
    private const UID = 1001;

    private const ENDPOINT = '/api/projects/alice/files/thumbnail';

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>';

    private const MVG = "push graphic-context\nviewbox 0 0 10 10\nrectangle 0,0 10,10\npop graphic-context\n";

    private ?string $tmpRoot = null;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->canReadAsAnotherUid() || !extension_loaded('imagick')) {
            $this->markTestSkipped('needs root, util-linux setpriv and imagick');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/pa-thumbnail-' . bin2hex(random_bytes(4));
        $home = $this->home();
        mkdir($home, 0755, true);
        chmod($this->tmpRoot, 0755);
        chmod($this->tmpRoot . '/home', 0755);

        $this->writeImages($home);
        file_put_contents($home . '/notes.jpg', "this is not an image\n");
        file_put_contents($home . '/drawing.png', self::SVG);
        file_put_contents($home . '/drawing.jpg', self::MVG);
        $this->writeBytes($home . '/misnamed.gif', 'png');
        $this->writeBytes($home . '/photo.png', 'png');
        file_put_contents($home . '/huge.png', self::hugePng());
        $this->writeSparse($home . '/oversize.jpg', FileThumbnail::MAX_SOURCE_BYTES + 1);
        file_put_contents($this->tmpRoot . '/secret', 'outside the home');
        symlink($this->tmpRoot . '/secret', $home . '/escapes.jpg');

        mkdir($this->tmpRoot . '/bin');
        file_put_contents($this->tmpRoot . '/bin/sudo', "#!/bin/sh\nexec \"\$@\"\n");
        chmod($this->tmpRoot . '/bin/sudo', 0755);
        $this->setEnv('PATH', $this->tmpRoot . '/bin:' . (string) getenv('PATH'));

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails(['template' => 'default', 'UID' => self::UID, 'GID' => self::UID]);
        $user->save();

        $this->app->instance(System::class, new class ($this->tmpRoot) extends System {
            public function __construct(private string $root)
            {
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }
        });
        $this->withoutMiddleware(Authenticate::class);
        if (in_array('sudophp', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('sudophp');
        }
        FileStreamWrapper::register();
    }

    protected function tearDown(): void
    {
        if ($this->tmpRoot === null) {
            parent::tearDown();

            return;
        }
        FileStreamWrapper::confineTo(null, ['sudo']);
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        Schema::dropIfExists('users');
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_a_wide_photo_becomes_a_small_square_cover_thumbnail(): void
    {
        $response = $this->get($this->url(['path' => 'photo.jpg']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('Cache-Control', 'max-age=86400, private');
        $this->assertLessThan(30 * 1024, strlen((string) $response->getContent()));

        $thumbnail = $this->decode((string) $response->getContent());
        $this->assertSame(176, $thumbnail->getImageWidth());
        $this->assertSame(176, $thumbnail->getImageHeight());
        $thumbnail->clear();
    }

    public function test_the_requested_geometry_is_honoured(): void
    {
        $response = $this->get($this->url(['path' => 'photo.jpg', 'width' => 100]));

        $response->assertOk();
        $thumbnail = $this->decode((string) $response->getContent());
        $this->assertSame(100, $thumbnail->getImageWidth());
        $this->assertSame(100, $thumbnail->getImageHeight());
        $thumbnail->clear();
    }

    public function test_contain_keeps_the_aspect_ratio_inside_the_box(): void
    {
        $response = $this->get($this->url(['path' => 'photo.jpg', 'fit' => 'contain']));

        $response->assertOk();
        $thumbnail = $this->decode((string) $response->getContent());
        $this->assertSame(176, $thumbnail->getImageWidth());
        $this->assertSame(132, $thumbnail->getImageHeight());
        $thumbnail->clear();
    }

    public function test_an_exif_rotated_photo_comes_out_upright(): void
    {
        $response = $this->get($this->url(['path' => 'rotated.jpg', 'fit' => 'contain']));

        $response->assertOk();
        $thumbnail = $this->decode((string) $response->getContent());
        $this->assertSame(132, $thumbnail->getImageWidth());
        $this->assertSame(176, $thumbnail->getImageHeight());
        $thumbnail->clear();
    }

    public function test_a_gif_thumbnails_its_first_frame(): void
    {
        $response = $this->get($this->url(['path' => 'anim.gif', 'fit' => 'contain']));

        $response->assertOk();
        $thumbnail = $this->decode((string) $response->getContent());
        $this->assertSame(176, $thumbnail->getImageWidth());
        $this->assertSame(141, $thumbnail->getImageHeight());
        $colour = $thumbnail->getImagePixelColor(5, 5)->getColor();
        $this->assertGreaterThan(200, $colour['r']);
        $this->assertLessThan(60, $colour['g']);
        $this->assertLessThan(60, $colour['b']);
        $thumbnail->clear();
    }

    public function test_a_jpeg_source_encodes_webp_bytes(): void
    {
        $response = $this->get($this->url(['path' => 'photo.jpg']));

        $response->assertOk();
        $this->assertWebp((string) $response->getContent());
    }

    public function test_a_png_source_encodes_webp_bytes(): void
    {
        $response = $this->get($this->url(['path' => 'photo.png']));

        $response->assertOk();
        $this->assertWebp((string) $response->getContent());
    }

    public function test_a_text_file_named_like_a_jpeg_is_unsupported(): void
    {
        $response = $this->getJson($this->url(['path' => 'notes.jpg']));

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'unsupported_image');
    }

    public function test_an_svg_named_like_a_png_is_unsupported(): void
    {
        $response = $this->getJson($this->url(['path' => 'drawing.png']));

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'unsupported_image');
    }

    public function test_an_mvg_named_like_a_jpeg_is_unsupported(): void
    {
        $response = $this->getJson($this->url(['path' => 'drawing.jpg']));

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'unsupported_image');
    }

    public function test_a_png_named_like_a_gif_is_thumbnailed_from_its_bytes(): void
    {
        $response = $this->get($this->url(['path' => 'misnamed.gif']));

        $response->assertOk();
        $thumbnail = $this->decode((string) $response->getContent());
        $this->assertSame(176, $thumbnail->getImageWidth());
        $this->assertSame(176, $thumbnail->getImageHeight());
        $thumbnail->clear();
    }

    public function test_a_huge_png_is_refused_before_it_is_decoded(): void
    {
        $response = $this->getJson($this->url(['path' => 'huge.png']));

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'image_too_large');
    }

    public function test_a_source_over_the_size_limit_is_refused(): void
    {
        $response = $this->getJson($this->url(['path' => 'oversize.jpg']));

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'image_too_large');
    }

    public function test_a_symlink_out_of_the_home_is_not_found(): void
    {
        $response = $this->getJson($this->url(['path' => 'escapes.jpg']));

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Invalid path']);
    }

    public function test_a_matching_etag_answers_not_modified(): void
    {
        $url = $this->url(['path' => 'photo.jpg']);
        $first = $this->get($url);
        $first->assertOk();
        $etag = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $second = $this->get($url, ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', (string) $second->getContent());
    }

    /**
     * @param array<string, string|int> $query
     */
    private function url(array $query): string
    {
        return self::ENDPOINT . '?' . http_build_query($query);
    }

    /**
     * A webp container: RIFF at the start and the WEBP fourcc at offset 8. The
     * decode() clone inherits the wand format pinned for reading, so an encoder
     * that only has setImageFormat('webp') writes the source format instead.
     */
    private function assertWebp(string $content): void
    {
        $this->assertSame('RIFF', substr($content, 0, 4));
        $this->assertSame('WEBP', substr($content, 8, 4));
    }

    private function decode(string $content): Imagick
    {
        $image = new Imagick();
        $image->readImageBlob($content);

        return $image;
    }

    /** An image written under a name that does not describe it. */
    private function writeBytes(string $path, string $format): void
    {
        $image = new Imagick();
        $image->newPseudoImage(40, 30, 'xc:red');
        $image->setImageFormat($format);
        file_put_contents($path, $image->getImageBlob());
        $image->clear();
    }

    private function home(): string
    {
        return $this->tmpRoot . '/home/alice';
    }

    private function writeImages(string $home): void
    {
        $photo = new Imagick();
        $photo->newPseudoImage(4000, 3000, 'gradient:red-blue');
        $photo->setImageFormat('jpeg');
        $photo->setImageCompressionQuality(90);
        $photo->writeImage($home . '/photo.jpg');
        $photo->clear();

        $this->writeRotated($home);

        $gif = new Imagick();
        $gif->setFormat('gif');
        $first = new Imagick();
        $first->newPseudoImage(100, 80, 'xc:red');
        $first->setImageDelay(10);
        $second = new Imagick();
        $second->newPseudoImage(200, 40, 'xc:blue');
        $second->setImageDelay(10);
        $gif->addImage($first);
        $gif->addImage($second);
        $gif->writeImages($home . '/anim.gif', true);
        $gif->clear();
    }

    /** Orientation 6 says the stored landscape pixels must be rotated to stand upright. */
    private function writeRotated(string $home): void
    {
        $jpeg = (string) file_get_contents($home . '/photo.jpg');
        $tiff = "II*\x00" . pack('V', 8) . pack('v', 1) . pack('vvVV', 0x0112, 3, 1, 6) . pack('V', 0);
        $exif = "Exif\x00\x00" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', 2 + strlen($exif)) . $exif;

        file_put_contents($home . '/rotated.jpg', substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
    }

    /** A valid PNG header claiming 20000x20000, with no pixels behind it. */
    private static function hugePng(): string
    {
        return "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0))
            . self::pngChunk('IDAT', (string) gzcompress(str_repeat("\x00", 100)))
            . self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private function writeSparse(string $path, int $bytes): void
    {
        $handle = fopen($path, 'w');
        fseek($handle, $bytes - 1);
        fwrite($handle, "\0");
        fclose($handle);
        chmod($path, 0644);
    }

    private function canReadAsAnotherUid(): bool
    {
        exec('setpriv --help 2>&1', $help);

        return function_exists('posix_geteuid')
            && posix_geteuid() === 0
            && str_contains(implode("\n", $help), '--reuid');
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}

<?php

namespace Tests\Unit\Upload;

use App\Exceptions\ProblemException;
use App\Lib\Project\ArchiveDrop;
use App\Lib\Upload\ArchiveFetch;
use App\Lib\Upload\ArchiveSource;
use App\Lib\Upload\ArchiveSources;
use App\Lib\Upload\Uploads;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * An archive deploy's archive becomes a file in the project, from a path, an upload
 * or a URL, and what was put in for the deploy is removed afterwards.
 */
class ArchiveSourcesTest extends TestCase
{
    use InMemoryDatabase;

    private \ArrayObject $calls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->calls = new \ArrayObject;
        $calls = $this->calls;
        $this->app->instance(ArchiveDrop::class, new class($calls) extends ArchiveDrop
        {
            public function __construct(private readonly \ArrayObject $calls) {}

            public function put(User $user, string $file, string $name, string $dir = self::TARGET_DIR): string
            {
                $this->calls[] = ['put', basename($file), $name, $dir];

                return $dir.'/.deploy-archive-test/'.$name;
            }

            public function remove(User $user, string $zipPath): void
            {
                $this->calls[] = ['remove', $zipPath];
            }
        });
        $this->app->instance(ArchiveFetch::class, new class($calls) extends ArchiveFetch
        {
            public function __construct(private readonly \ArrayObject $calls) {}

            public function into(User $user, string $url): string
            {
                if (str_contains($url, 'broken')) {
                    throw new InvalidArgumentException("The file at {$url} is not a .zip or .tar.gz archive.");
                }
                $this->calls[] = ['fetch', $url];

                return '/project/.deploy-archive-url.tar.gz';
            }
        });
    }

    public function test_a_path_is_used_as_it_is_and_kept(): void
    {
        $user = $this->makeUser('pathzip', ['template' => 'dind']);
        $source = ArchiveSource::path('/project/app.zip');

        $zip = $this->archives()->materialise($user, $source);
        $this->archives()->release($user, $source, $zip);

        $this->assertSame('/project/app.zip', $zip);
        $this->assertSame([], $this->calls->getArrayCopy());
    }

    public function test_a_claimed_upload_is_moved_in_under_the_name_it_was_uploaded_by_and_removed_after(): void
    {
        $user = $this->makeUser('upzip', ['template' => 'dind']);
        $upload = $this->upload('upzip', 'site v2.zip');
        $source = ArchiveSource::upload($upload->id);

        $zip = $this->archives()->materialise($user, $source);
        $this->archives()->release($user, $source, $zip);

        $this->assertSame('/project/.deploy-archive-test/site v2.zip', $zip);
        $this->assertSame([['put', 'app.zip', 'site v2.zip', '/project'], ['remove', $zip]], $this->calls->getArrayCopy());
        $this->assertNull(Upload::query()->find($upload->id));
    }

    public function test_an_upload_another_project_holds_is_gone(): void
    {
        $user = $this->makeUser('thief', ['template' => 'dind']);
        $upload = $this->upload('owner');

        try {
            $this->archives()->materialise($user, ArchiveSource::upload($upload->id));
            $this->fail('another project\'s upload was used');
        } catch (ProblemException $e) {
            $this->assertSame([['source.upload_id', 'upload_gone']], array_map(static fn (array $p): array => [$p['field'], $p['code']], $e->problems));
        }
        $this->assertNotNull(Upload::query()->find($upload->id));
    }

    public function test_a_url_is_downloaded_and_a_failed_one_named(): void
    {
        $user = $this->makeUser('urlzip', ['template' => 'dind']);

        $zip = $this->archives()->materialise($user, ArchiveSource::url('https://example.com/app.tar.gz'));
        $this->assertSame('/project/.deploy-archive-url.tar.gz', $zip);

        try {
            $this->archives()->materialise($user, ArchiveSource::url('https://example.com/broken'));
            $this->fail('a failed download was not refused');
        } catch (ProblemException $e) {
            $this->assertSame('source.url', $e->problems[0]['field']);
            $this->assertSame('archive_download_failed', $e->problems[0]['code']);
        }
    }

    public function test_a_failed_download_names_the_url_without_its_signature(): void
    {
        $user = $this->makeUser('signedzip', ['template' => 'dind']);

        try {
            $this->archives()->materialise($user, ArchiveSource::url('https://bucket.example.com/broken.zip?X-Amz-Credential=AKIA%2Fx&X-Amz-Signature=abc123#part'));
            $this->fail('a failed download was not refused');
        } catch (ProblemException $e) {
            $this->assertSame('The file at https://bucket.example.com/broken.zip is not a .zip or .tar.gz archive.', $e->problems[0]['message']);
        }
    }

    public function test_a_signed_url_is_downloaded_whole(): void
    {
        $url = 'https://bucket.example.com/app.tar.gz?X-Amz-Signature=abc123';

        $this->archives()->materialise($this->makeUser('fullurl', ['template' => 'dind']), ArchiveSource::url($url));

        $this->assertSame([['fetch', $url]], $this->calls->getArrayCopy());
    }

    public function test_every_url_in_a_message_loses_its_credentials_and_query(): void
    {
        $this->assertSame(
            'cURL error 28: timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://objects.example.net/a.zip',
            ArchiveSource::withShownUrls('cURL error 28: timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://u:p@objects.example.net/a.zip?X-Amz-Signature=def'),
        );
        $this->assertSame('Downloading `GET http://[2001:db8::1]:8080/a.zip` failed', ArchiveSource::withShownUrls('Downloading `GET http://[2001:db8::1]:8080/a.zip?token=x` failed'));
        $this->assertSame('no address here', ArchiveSource::withShownUrls('no address here'));
    }

    public function test_it_travels_to_the_job_and_back(): void
    {
        $source = ArchiveSource::fromArray(ArchiveSource::url('https://example.com/a.zip')->toArray());

        $this->assertSame(['kind' => 'url', 'value' => 'https://example.com/a.zip'], $source?->toArray());
        $this->assertTrue($source?->isDropped());
        $this->assertFalse(ArchiveSource::path('/project/a.zip')->isDropped());
        $this->assertNull(ArchiveSource::fromArray(['kind' => 'ftp', 'value' => 'x']));
        $this->assertNull(ArchiveSource::fromArray(null));
    }

    public function test_it_names_the_one_key_it_came_by_on_the_project_and_the_task(): void
    {
        $url = ArchiveSource::url('https://deploy:s3cret@example.com/app.zip');

        $this->assertSame(['type' => 'archive', 'upload_id' => 'upl_x'], ArchiveSource::upload('upl_x')->recorded());
        $this->assertSame(['type' => 'archive', 'path' => '/project/app.zip'], ArchiveSource::path('/project/app.zip')->recorded());
        $this->assertStringNotContainsString('s3cret', $url->recorded()['url']);
        $this->assertSame(['upload_id' => 'upl_x'], ArchiveSource::upload('upl_x')->taskDetails());
        $this->assertSame(['archive_url' => $url->recorded()['url']], $url->taskDetails());
        $this->assertSame([], ArchiveSource::path('/project/app.zip')->taskDetails());
    }

    public function test_a_presigned_url_is_recorded_and_shown_without_its_query_or_fragment(): void
    {
        $signed = 'https://deploy:s3cret@bucket.example.com:8443/releases/app.zip?X-Amz-Credential=AKIA%2F20261107&X-Amz-Signature=abc123#part';
        $url = ArchiveSource::url($signed);

        $this->assertSame(['type' => 'archive', 'url' => 'https://bucket.example.com:8443/releases/app.zip'], $url->recorded());
        $this->assertSame(['archive_url' => 'https://bucket.example.com:8443/releases/app.zip'], $url->taskDetails());
        $this->assertSame(['kind' => 'url', 'value' => $signed], $url->toArray());
    }

    public function test_a_url_the_outbound_guard_refuses_is_named_before_anything_is_stored(): void
    {
        ArchiveSources::assertReachable(null);

        try {
            ArchiveSources::assertReachable('http://'.TestNetwork::LOOPBACK.'/app.zip');
            $this->fail('a loopback URL was not refused');
        } catch (ProblemException $e) {
            $this->assertSame([['source.url', 'host_refused']], array_map(static fn (array $p): array => [$p['field'], $p['code']], $e->problems));
        }
    }

    public function test_a_usable_upload_is_claimed_and_one_claimed_first_elsewhere_is_refused(): void
    {
        $free = $this->upload(null);
        $this->assertSame('shop', app(Uploads::class)->claimUsable($free->id, 'source.upload_id', 'shop')->claimed_by);
        $this->assertSame('shop', $free->refresh()->claimed_by);

        $raced = $this->upload(null);
        $uploads = new class extends Uploads
        {
            public function claim(Upload $upload, string $by): bool
            {
                return false;
            }
        };
        try {
            $uploads->claimUsable($raced->id, 'source.upload_id', 'late');
            $this->fail('an upload claimed by another create was handed out again');
        } catch (ProblemException $e) {
            $this->assertSame([['source.upload_id', 'upload_in_use']], array_map(static fn (array $p): array => [$p['field'], $p['code']], $e->problems));
            $this->assertSame("Upload {$raced->id} was just claimed by another create.", $e->problems[0]['message']);
        }
    }

    private function archives(): ArchiveSources
    {
        return app(ArchiveSources::class);
    }

    /** @param string $filename the name it was uploaded by, which the stored copy need not carry */
    private function upload(?string $claimedBy, string $filename = 'app.zip'): Upload
    {
        $id = 'upl_'.bin2hex(random_bytes(8));
        $dir = storage_path('app/uploads/'.$id);
        @mkdir($dir, 0700, true);
        file_put_contents($dir.'/app.zip', "PK\x03\x04");
        $this->beforeApplicationDestroyed(static function () use ($dir): void {
            @unlink($dir.'/app.zip');
            @rmdir($dir);
        });

        return Upload::create([
            'id' => $id,
            'filename' => $filename,
            'size' => 4,
            'path' => $id.'/app.zip',
            'claimed_by' => $claimedBy,
            'expires_at' => Carbon::now()->addHour(),
        ]);
    }
}

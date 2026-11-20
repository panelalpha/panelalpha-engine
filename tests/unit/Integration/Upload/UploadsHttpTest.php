<?php

namespace Tests\Integration\Upload;

use App\Http\Middleware\Authenticate;
use App\Lib\Upload\Uploads;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST/GET/DELETE /uploads: an archive waiting for the create that names it.
 */
class UploadsHttpTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        foreach (Upload::query()->get() as $upload) {
            app(Uploads::class)->delete($upload);
        }
        parent::tearDown();
    }

    public function test_an_archive_is_stored_and_described(): void
    {
        $response = $this->post('/api/uploads', ['file' => UploadedFile::fake()->createWithContent('shop.tar.gz', "\x1f\x8b archive")], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $id = (string) $response->json('data.id');
        $this->assertStringStartsWith('upl_', $id);
        $response->assertJsonPath('data.filename', 'shop.tar.gz');
        $response->assertJsonPath('data.size', 10);
        $response->assertJsonPath('data.claimed_by', null);
        $upload = Upload::query()->findOrFail($id);
        $this->assertStringEqualsFile(app(Uploads::class)->absolutePath($upload), "\x1f\x8b archive");
        $this->assertTrue($upload->expires_at->between(Carbon::now()->addHours(23), Carbon::now()->addHours(25)));

        $this->getJson("/api/uploads/{$id}")->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_a_file_not_named_as_an_archive_is_refused(): void
    {
        $response = $this->post('/api/uploads', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'file');
        $response->assertJsonPath('problems.0.code', 'file_not_an_archive');
        $this->assertSame(0, Upload::query()->count());
    }

    public function test_an_unclaimed_upload_can_be_deleted_and_a_claimed_one_cannot(): void
    {
        $free = $this->stored('free.zip');
        $taken = $this->stored('taken.zip');
        app(Uploads::class)->claim($taken, 'shop');

        $this->deleteJson("/api/uploads/{$free->id}")->assertNoContent();
        $this->assertNull(Upload::query()->find($free->id));
        $this->assertFileDoesNotExist(app(Uploads::class)->absolutePath($free));

        $this->deleteJson("/api/uploads/{$taken->id}")->assertStatus(409);
        $this->getJson('/api/uploads/upl_nosuch')->assertNotFound();
    }

    public function test_expired_uploads_are_pruned(): void
    {
        $old = $this->stored('old.zip');
        $old->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();
        $fresh = $this->stored('fresh.zip');

        $this->getJson("/api/uploads/{$old->id}")->assertNotFound();
        $this->assertSame(0, Artisan::call('uploads:prune'));

        $this->assertNull(Upload::query()->find($old->id));
        $this->assertNotNull(Upload::query()->find($fresh->id));
    }

    private function stored(string $name): Upload
    {
        return app(Uploads::class)->store(UploadedFile::fake()->createWithContent($name, "PK\x03\x04"));
    }
}

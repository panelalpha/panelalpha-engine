<?php

namespace Tests\Integration\Console;

use App\Lib\Project\ArchiveDrop;
use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `pae project:deploy-archive`: a refused archive that cannot be taken back out of the project is named, and the refusal still stands. */
class DeployArchiveRemoveFailureTest extends TestCase
{
    use InMemoryDatabase;

    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        Queue::fake();
        $this->archive = sys_get_temp_dir() . '/pa-archive-rm-' . bin2hex(random_bytes(4)) . '/win.zip';
        mkdir(dirname($this->archive));
        file_put_contents($this->archive, 'PK');
        $this->app->instance(ArchiveDrop::class, new class () extends ArchiveDrop {
            public function __construct()
            {
            }

            public function put(User $user, string $file, string $name, string $dir = self::TARGET_DIR): string
            {
                return $dir . '/.deploy-archive-test/' . $name;
            }

            public function remove(User $user, string $zipPath): void
            {
                throw new \RuntimeException('Permission denied');
            }
        });
        $this->app->instance(ProjectRebuild::class, new class () extends ProjectRebuild {
            public function __construct()
            {
            }

            public function assertArchive(User $user, string $zipPath): void
            {
                throw ValidationException::withMessages(['zip_path' => 'Not a .zip or .tar.gz archive.']);
            }
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->archive);
        @rmdir(dirname($this->archive));
        parent::tearDown();
    }

    public function test_an_archive_left_behind_is_named_and_the_refusal_reaches_the_caller(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $refused = null;

        try {
            Artisan::call('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive]);
        } catch (ValidationException $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused);
        $this->assertStringContainsString('The archive ' . ArchiveDrop::TARGET_DIR . '/.deploy-archive-test/win.zip could not be removed from the project.', Artisan::output());
        $this->assertSame(['zip_path' => ['Not a .zip or .tar.gz archive.']], $refused->errors());
        Queue::assertNothingPushed();
    }
}

<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use PHPUnit\Framework\TestCase;

/**
 * Reading back the image the account will actually run.
 *
 * `SourcePreparation` freezes `deploy_image` from the detection decision, which
 * is right at the time and stale by the time anything reads it — a strategy
 * then swaps in the shared base, the lockfile's interpreter, or the Bun image.
 * For example a Python account whose snapshot said `python:3.12-slim` while its
 * container ran `panelalpha/python:3.12-slim-pa2a0d75ad`.
 *
 * It is not only cosmetic. `AppLauncher` provisions `getDeployImage()` by
 * name, so a stale snapshot seeds an image nothing runs and leaves the real
 * one to be found later — the shape of the problem provisioning-by-name was
 * added to fix.
 */
class AppImageSnapshotTest extends TestCase
{
    /**
     * The compose file is the authority: it is what `compose up` obeys, so the
     * snapshot is read back from it rather than from a value passed alongside.
     */
    public function test_the_php_base_is_read_back_not_the_upstream_tag(): void
    {
        $base = (string) PhpBaseImage::tag('php:8.3-apache-bookworm');
        $yaml = DeployCompose::framework(
            ['runtime' => 'php', 'image' => $base, 'env' => []],
            PhpBaseImage::PORT,
            null
        );

        $this->assertSame($base, GeneratedCompose::appImage($yaml));
        $this->assertNotSame('php:8.3-apache-bookworm', GeneratedCompose::appImage($yaml));
    }

    public function test_a_mounted_python_project_reports_its_base(): void
    {
        $yaml = DeployCompose::framework(
            [
                'runtime' => 'command',
                'strategy' => 'django',
                'image' => 'panelalpha/python:3.12-slim-pa2a0d75ad',
                'start_command' => '.venv/bin/python manage.py runserver 0.0.0.0:8000',
                'env' => [],
            ],
            8000,
            null
        );

        $this->assertSame('panelalpha/python:3.12-slim-pa2a0d75ad', GeneratedCompose::appImage($yaml));
    }

    public function test_a_node_project_reports_the_interpreter_it_runs(): void
    {
        $yaml = DeployCompose::framework(
            [
                'runtime' => 'node',
                'strategy' => 'nextjs',
                'image' => 'node:22-bookworm-slim',
                'start_command' => 'npm start',
                'env' => [],
            ],
            3000,
            null
        );

        $this->assertSame('node:22-bookworm-slim', GeneratedCompose::appImage($yaml));
    }

    /**
     * Null means "no better answer than the one already frozen". A guess would
     * be worse: it would overwrite a correct snapshot with a wrong one.
     */
    public function test_unparseable_or_shapeless_input_answers_nothing(): void
    {
        $this->assertNull(GeneratedCompose::appImage("services:\n  app:\n   image: [\n"));
        $this->assertNull(GeneratedCompose::appImage(''));
        $this->assertNull(GeneratedCompose::appImage("services:\n  db:\n    image: mysql:8.4\n"));
        $this->assertNull(GeneratedCompose::appImage("services:\n  app:\n    build: .\n"));
        $this->assertNull(GeneratedCompose::appImage("services:\n  app:\n    image: '   '\n"));
    }

    /**
     * A sidecar's image must never be mistaken for the application's — the
     * snapshot drives provisioning, and seeding mysql in place of the runtime
     * would leave the real image to be discovered late.
     */
    public function test_a_sidecar_image_is_never_reported(): void
    {
        $yaml = DeployCompose::framework(
            [
                'runtime' => 'php',
                'image' => 'panelalpha/php:8.3-apache-bookworm-paf53449c0',
                'mysql' => true,
                'env' => [],
            ],
            PhpBaseImage::PORT,
            null
        );

        $image = GeneratedCompose::appImage($yaml);

        $this->assertStringStartsWith('panelalpha/php:', (string) $image);
        $this->assertStringNotContainsString('mysql', (string) $image);
    }
}

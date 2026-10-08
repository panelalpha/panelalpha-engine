<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What a host-compiled project's container is told to run.
 *
 * The case this exists for: a Django project deployed green, its health probe
 * followed the redirect off the homepage, and its first real page was a 500 on
 * `no such table: catalog_book`. The recipe declares `migrate` and
 * `collectstatic` in its install and upgrade stages; the generated compose
 * command was `exec .venv/bin/python manage.py runserver 0.0.0.0:8000` and
 * nothing else, because this path never wrote the staged entrypoint that runs
 * a platform's non-start commands. A generated build bakes that script into
 * the image and PHP's shared base carries a shim for it; only the mounted
 * path had no way to reach it.
 */
class MountedProjectEntrypointTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function service(array $extra = []): array
    {
        $yaml = DeployCompose::framework([
            'strategy' => 'django',
            'runtime' => 'command',
            'image' => 'panelalpha/python:3.12-slim',
            'start_command' => '.venv/bin/python manage.py runserver 0.0.0.0:8000',
            'env' => [],
        ] + $extra, 8000, null);

        return Yaml::parse($yaml)['services']['app'];
    }

    public function test_the_entrypoint_is_the_command_when_one_was_written(): void
    {
        $service = $this->service(['entrypoint' => StageScript::FILENAME]);

        $this->assertSame(
            ['sh', '-c', 'exec /app/' . StageScript::FILENAME],
            $service['command'],
            'the script runs install and upgrade before the serve command, so it is the command'
        );
    }

    /**
     * The regression itself: without the entrypoint the container is handed
     * the serve command alone, and every other stage the recipe declared is
     * silently dropped.
     */
    public function test_without_one_the_serve_command_runs_alone(): void
    {
        $service = $this->service();

        $this->assertSame(
            ['sh', '-c', 'exec .venv/bin/python manage.py runserver 0.0.0.0:8000'],
            $service['command']
        );
    }

    /**
     * The project is still mounted and still owned by the account either way
     * -- the entrypoint changes what runs, not where or as whom.
     */
    public function test_the_mount_and_the_account_identity_survive_the_entrypoint(): void
    {
        $service = $this->service(['entrypoint' => StageScript::FILENAME, 'user' => '1009:1009']);

        $this->assertSame('/app', $service['working_dir']);
        $this->assertSame('1009:1009', $service['user']);
        $this->assertContains('8000:8000', $service['ports']);
    }

    public function test_an_empty_entrypoint_is_not_an_entrypoint(): void
    {
        $this->assertSame(
            ['sh', '-c', 'exec .venv/bin/python manage.py runserver 0.0.0.0:8000'],
            $this->service(['entrypoint' => '  '])['command']
        );
    }
}

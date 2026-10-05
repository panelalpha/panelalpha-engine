<?php

namespace Tests\Unit\Deploy\Env;

use App\Lib\Deploy\Env\EnvExampleCopies;
use PHPUnit\Framework\TestCase;

/**
 * The .env files a checkout needs before it can be deployed.
 *
 * Repositories commit `.env.example` and gitignore `.env`, so a fresh clone
 * has neither the file the framework reads nor the one compose insists on
 * before it will start anything. Both failures are early and total: Laravel
 * refuses to boot without APP_KEY, and compose refuses to start a service
 * whose env_file is missing.
 *
 * Nothing here writes; it plans the copies, so it can be tested against a
 * directory.
 */
class EnvExampleCopiesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-env-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    private function write(string $relative, string $contents = ''): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @return list<string> the relative destination of each planned copy
     */
    private function planned(): array
    {
        return array_column(EnvExampleCopies::for($this->dir), 'relative');
    }

    public function test_a_root_example_is_copied_to_the_file_the_app_reads(): void
    {
        $this->write('.env.example', "APP_ENV=local\n");

        $this->assertSame(['.env'], $this->planned());
    }

    public function test_an_env_the_project_already_has_is_not_overwritten(): void
    {
        // It may carry the keys from a previous deploy of this same account.
        $this->write('.env.example', "APP_ENV=local\n");
        $this->write('.env', "APP_KEY=base64:already-set\n");

        $this->assertSame([], $this->planned());
    }

    public function test_a_project_with_no_example_needs_no_copy(): void
    {
        $this->write('README.md', '# nothing');

        $this->assertSame([], $this->planned());
    }

    public function test_an_example_in_a_workspace_is_copied_beside_itself(): void
    {
        // A monorepo's apps/api reads its own .env, not the root one.
        $this->write('.env.example', "APP_ENV=local\n");
        $this->write('apps/api/.env.example', "DATABASE_URL=postgres://localhost/api\n");

        $planned = $this->planned();

        $this->assertContains('.env', $planned);
        $this->assertContains('apps/api/.env', $planned);
    }

    public function test_the_search_does_not_descend_into_dependency_directories(): void
    {
        // node_modules and vendor contain hundreds of package .env.examples,
        // none of which belong to this project.
        $this->write('node_modules/some-pkg/.env.example', 'X=1');
        $this->write('vendor/acme/lib/.env.example', 'X=1');
        $this->write('.git/modules/x/.env.example', 'X=1');

        $this->assertSame([], $this->planned());
    }

    public function test_the_search_does_not_go_arbitrarily_deep(): void
    {
        // A bounded walk: an .env five directories down is not this project's
        // configuration, and scanning a large monorepo without a bound is slow.
        $this->write('a/b/c/d/e/.env.example', 'X=1');

        $this->assertSame([], $this->planned());
    }

    public function test_a_file_compose_insists_on_is_planned_even_with_no_example(): void
    {
        // Compose refuses to start the stack at all, so the file has to exist
        // even if there is nothing to put in it.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
        YAML);

        $copies = EnvExampleCopies::for($this->dir);

        $this->assertSame(['.env'], array_column($copies, 'relative'));
        $this->assertSame('', $copies[0]['example'], 'created empty, since there is nothing to copy');
    }

    public function test_a_compose_declared_file_prefers_a_nearby_example(): void
    {
        $this->write('.env.example', "APP_ENV=local\n");
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
        YAML);

        $copies = EnvExampleCopies::for($this->dir);

        $this->assertSame($this->dir . '/.env.example', $copies[0]['example']);
    }

    /** openstatus: `env_file: .env.docker` beside `.env.docker.example`; the root `.env` lacks its keys. */
    public function test_a_compose_declared_file_takes_its_own_template_first(): void
    {
        $this->write('.env', "WEB_PORT=8080\n");
        $this->write('.env.example', "WEB_PORT=8080\n");
        $this->write('.env.docker.example', "RESEND_API_KEY=re_your_resend_api_key_here\n");
        $this->write('apps/worker/.env.worker.sample', "QUEUE=default\n");
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env.docker
          worker:
            image: acme/worker
            env_file: apps/worker/.env.worker
        YAML);

        $sources = array_column(EnvExampleCopies::for($this->dir), 'example', 'relative');

        $this->assertSame($this->dir . '/.env.docker.example', $sources['.env.docker']);
        $this->assertSame($this->dir . '/apps/worker/.env.worker.sample', $sources['apps/worker/.env.worker']);
    }

    public function test_a_compose_declared_file_in_a_directory_that_is_not_there_is_skipped(): void
    {
        // Copying into it would mean creating directories a customer's file
        // named but the repository does not have.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: config/env/.env
        YAML);

        $this->assertSame([], $this->planned());
    }

    public function test_a_local_example_is_copied_to_env_local(): void
    {
        $this->write('.env.local.example', "NEXT_PUBLIC_URL=http://localhost:3000\n");

        $this->assertContains('.env.local', $this->planned());
    }

    public function test_a_framework_that_reads_env_local_gets_one_from_the_env(): void
    {
        // Next.js reads .env.local ahead of .env, and a project whose config
        // references it will not find its own settings without one.
        $this->write('.env.example', "DATABASE_URL=postgres://localhost/app\n");
        $this->write('next.config.mjs', "// loads .env.local\nexport default {};");

        $this->assertContains('.env.local', $this->planned());
    }

    public function test_a_project_that_never_mentions_env_local_does_not_get_one(): void
    {
        $this->write('.env.example', "APP_ENV=local\n");
        $this->write('composer.json', '{}');

        $this->assertSame(['.env'], $this->planned());
    }

    public function test_an_env_local_the_project_already_has_is_left_alone(): void
    {
        $this->write('.env.local.example', 'X=1');
        $this->write('.env.local', 'X=already-set');

        $this->assertNotContains('.env.local', EnvExampleCopies::local($this->dir));
    }

    public function test_a_directory_that_is_not_there_needs_nothing(): void
    {
        $this->assertSame([], EnvExampleCopies::for($this->dir . '/nope'));
        $this->assertSame([], EnvExampleCopies::for(''));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

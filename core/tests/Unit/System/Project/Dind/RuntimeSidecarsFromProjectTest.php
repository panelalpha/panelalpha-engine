<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\RuntimeSidecars;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\AccountSecrets;
use PHPUnit\Framework\TestCase;

/**
 * Ticket 04: a recipe repo (framework/Dockerfile/Railpack strategy, not the
 * compose strategy) that ships its own local-dev compose file -- a Postgres
 * sidecar beside an `app` service built from the repo's own root, Sail-
 * or Symfony-Docker-shaped.
 *
 * {@see RuntimeSidecars::runtimeSidecarsFromProject()} only ever *reads*
 * that file through {@see ProjectFiles::read()} to harvest its backing
 * services; nothing in this class writes anything, which is what makes the
 * three promises here (no stray stash file, the sidecar survives, the
 * client's own compose file is untouched) hold simultaneously -- proven here
 * by driving the real method rather than asserting the absence of a write
 * by reading the source.
 */
class RuntimeSidecarsFromProjectTest extends TestCase
{
    private const PROJECT_DIR = '/home/acme/project';

    /** @var array<string, string> target path => contents, from every `sudo cp` a write issued */
    private array $copiedTo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->copiedTo = [];
    }

    /**
     * @param array<string, string> $files absolute account path => contents
     */
    private function stubbedSystem(array $files): System
    {
        $copiedTo = &$this->copiedTo;

        return new class ($files, $copiedTo) extends System {
            /** @param array<string, string> $files
             *  @param array<string, string> $copiedTo */
            public function __construct(private array $files, private array &$copiedTo)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;
                $engine = $this;

                return new class ($engine, $files) extends SystemFilesystem {
                    /** @param array<string, string> $files */
                    public function __construct(System $engine, private array $files)
                    {
                        parent::__construct($engine);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->files[$path] ?? '';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (preg_match('/^sudo cp (\S+) (\S+)$/', $line, $m) === 1 && is_file($m[1])) {
                    $this->copiedTo[$m[2]] = (string) file_get_contents($m[1]);
                }

                return '';
            }
        };
    }

    private function stubbedDind(System $system): Dind
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        $innerDocker = $this->createStub(InnerDocker::class);
        $innerDocker->method('declaredImagePorts')->willReturn([]);
        $dind->method('innerDocker')->willReturn($innerDocker);

        // placeholderSeed() reaches strategy()->secrets()->for(...) eagerly,
        // and the real AccountSecrets derives from config('app.key'), which
        // nothing in this bare PHPUnit process has bootstrapped.
        $strategy = new class ($dind) extends DeployStrategy {
            public function secrets(): AccountSecrets
            {
                return new class ($this) extends AccountSecrets {
                    public function __construct(object $unused)
                    {
                    }

                    public function userEnvVars(): array
                    {
                        return [];
                    }

                    public function for(string $purpose): string
                    {
                        return 'stub-seed:' . $purpose;
                    }
                };
            }
        };
        $dind->method('strategy')->willReturn($strategy);

        return $dind;
    }

    public function test_a_recipes_local_dev_sidecar_is_kept_with_no_stash_file_and_the_client_file_untouched(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $clientYaml = <<<'YAML'
        services:
          app:
            build: .
            volumes:
              - .:/var/www/html
            ports:
              - "8000:8000"
          db:
            image: postgres:16
            environment:
              POSTGRES_USER: acme
              POSTGRES_PASSWORD: secret
              POSTGRES_DB: acme
            volumes:
              - dbdata:/var/lib/postgresql/data
        volumes:
          dbdata:
        YAML;

        $system = $this->stubbedSystem([$clientPath => $clientYaml]);
        $dind = $this->stubbedDind($system);

        $result = (new RuntimeSidecars($dind))->runtimeSidecarsFromProject(self::PROJECT_DIR);

        // The sidecar is kept...
        $this->assertSame(['db'], array_keys($result['services']), 'the Postgres sidecar must survive extraction');

        // ...nothing was ever written back, so no `*.panelalpha-local` stash
        // file (the previous engine's mechanism) and no write to the client's
        // own file either -- this class only reads.
        $this->assertSame([], $this->copiedTo, 'reading a project for its sidecars must never write anything');
        $this->assertSame(
            $clientYaml,
            $system->filesystem()->fileGetContents($clientPath),
            "the client's own compose file must stay exactly what it shipped"
        );
    }
}

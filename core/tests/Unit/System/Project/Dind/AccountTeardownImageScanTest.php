<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\ImageStore;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountTeardown;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Ticket 04: before an account is torn down, the engine scans compose files
 * for images still in use so the host cache purge does not delete one a
 * *different* account still needs. {@see AccountTeardown::delete()} reads
 * both {@see Dind::userAppComposeFilePath()} (the run file, ADR-0001) and
 * {@see Dind::userAppExistingComposeFilePath()} (the client's own, when it
 * still differs) for that scan -- nothing exercised this before. Driven
 * through reflection since the scan is a private step of a `delete()` that
 * otherwise reaches storage/inner-Docker collaborators this test has no
 * reason to stub.
 */
class AccountTeardownImageScanTest extends TestCase
{
    private const RUN_PATH = '/home/acme/project/docker-compose.panelalpha.yml';

    private const CLIENT_PATH = '/home/acme/project/docker-compose.yml';

    /**
     * @param array<string, string> $files
     */
    private function stubbedSystem(array $files): System
    {
        return new class ($files) extends System {
            /** @param array<string, string> $files */
            public function __construct(private array $files)
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
                return '';
            }
        };
    }

    public function test_the_scan_reads_both_the_run_file_and_the_clients_own_file(): void
    {
        $files = [
            self::RUN_PATH => "services:\n  app:\n    image: hardened-app:latest\n",
            self::CLIENT_PATH => "services:\n  app:\n    image: client-app:latest\n",
        ];
        $system = $this->stubbedSystem($files);

        $images = $this->createStub(ImageStore::class);
        $images->method('listImagesArgv')->willReturn(['docker', 'images']);
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('images')->willReturn($images);

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('engine')->willReturn($engine);
        $dind->method('userAppComposeFilePath')->willReturn(self::RUN_PATH);
        $dind->method('userAppExistingComposeFilePath')->willReturn(self::CLIENT_PATH);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        $method = new ReflectionMethod(AccountTeardown::class, 'accountImageRefsForHostCleanup');
        $method->setAccessible(true);
        /** @var list<string> $refs */
        $refs = $method->invoke(new AccountTeardown($dind));

        $this->assertNotEmpty(
            array_filter($refs, static fn (string $ref): bool => str_contains($ref, 'hardened-app')),
            'the image the run file declares must be in the preserve list: ' . implode(', ', $refs)
        );
        $this->assertNotEmpty(
            array_filter($refs, static fn (string $ref): bool => str_contains($ref, 'client-app')),
            "the image the client's own file declares must also be in the preserve list: " . implode(', ', $refs)
        );
    }
}

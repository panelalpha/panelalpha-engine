<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Privileged;
use App\System\Project\Dind\SystemAppConfigSource;
use PHPUnit\Framework\TestCase;

/** The files under an app config directory, as root lists them: sorted, blank lines dropped, none when it cannot say. */
class SystemAppConfigSourceListFilesTest extends TestCase
{
    private const DIR = '/home/acme/project/.panelalpha';

    /** @var list<list<string>> */
    private array $asked = [];

    public function test_the_listing_is_sorted_and_blank_lines_are_dropped(): void
    {
        $files = $this->source(true, static fn (): string => "files/b.conf\n\n  app.yml \nfiles/a.conf\n")->listFiles(self::DIR);

        $this->assertSame(['app.yml', 'files/a.conf', 'files/b.conf'], $files);
        $this->assertSame([Privileged::files()->relativeFilePaths(self::DIR)], $this->asked);
    }

    public function test_a_listing_root_refuses_is_empty(): void
    {
        $files = $this->source(true, static fn (): string => throw new \RuntimeException('denied'))->listFiles(self::DIR);

        $this->assertSame([], $files);
    }

    public function test_a_path_that_is_not_a_directory_is_never_listed(): void
    {
        $this->assertSame([], $this->source(false, static fn (): string => "app.yml\n")->listFiles(self::DIR));
        $this->assertSame([], $this->asked);
    }

    /** @param \Closure(): string $listing */
    private function source(bool $isDirectory, \Closure $listing): SystemAppConfigSource
    {
        $filesystem = $this->createStub(SystemFilesystem::class);
        $filesystem->method('directoryExists')->willReturn($isDirectory);
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use ($listing): string {
            $this->asked[] = (array) $cmd;

            return $listing();
        });

        return new SystemAppConfigSource($system);
    }
}

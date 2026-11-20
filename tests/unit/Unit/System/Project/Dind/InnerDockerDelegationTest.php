<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\Inner\StorageReclaim;
use App\System\Project\Dind\InnerDocker;
use PHPUnit\Framework\TestCase;

/**
 * InnerDocker keeps one of each collaborator per account and hands every question to the one that owns it,
 * passing the arguments through and the answer back.
 */
class InnerDockerDelegationTest extends TestCase
{
    public function test_each_collaborator_is_made_once_and_belongs_to_the_account(): void
    {
        $dind = $this->createStub(Dind::class);
        $inner = new InnerDocker($dind);

        $this->assertSame($dind, $inner->dind());
        $this->assertInstanceOf(ImageSeeding::class, $inner->seeding());
        $this->assertSame($inner->seeding(), $inner->seeding());
        $this->assertInstanceOf(SharedBaseImages::class, $inner->bases());
        $this->assertSame($inner->bases(), $inner->bases());
        $this->assertInstanceOf(StorageReclaim::class, $inner->storage());
        $this->assertSame($inner->storage(), $inner->storage());
        $this->assertInstanceOf(HostCommands::class, $inner->host());
        $this->assertSame($inner->host(), $inner->host());
    }

    public function test_image_questions_go_to_the_seeding(): void
    {
        $seeding = $this->createMock(ImageSeeding::class);
        $seeding->expects($this->once())->method('preloadFramework')->with('php', 'php');
        $seeding->expects($this->once())->method('preloadRailpack')->with(['ghcr.io/railwayapp/railpack-frontend:v1']);
        $seeding->expects($this->once())->method('preloadCompose')->with('/home/u/docker-compose.yml');
        $seeding->expects($this->once())->method('preloadOperatorBases')->with('/home/u/docker-compose.yml');
        $seeding->expects($this->once())->method('missingFrom')->with('/home/u/other.yml')->willReturn(['redis:7']);
        $seeding->expects($this->once())->method('ensure')->with('mysql:8.0');
        $seeding->expects($this->once())->method('ensureCurrent')->with('node:22-bookworm-slim');
        $seeding->expects($this->once())->method('declaredImagePorts')->with('redis:7')->willReturn([6379]);
        $seeding->expects($this->once())->method('imageEnvironment')->with('postgres:16')->willReturn(['PGDATA=/data']);
        $inner = $this->inner(seeding: $seeding);

        $inner->preloadFrameworkBaseImages('php', 'php');
        $inner->preloadRailpackBaseImages(['ghcr.io/railwayapp/railpack-frontend:v1']);
        $inner->preloadComposeImages('/home/u/docker-compose.yml');
        $this->assertSame(['redis:7'], $inner->missingComposeImages('/home/u/other.yml'));
        $inner->ensureImage('mysql:8.0');
        $inner->ensureCurrentImage('node:22-bookworm-slim');
        $this->assertSame([6379], $inner->declaredImagePorts('redis:7'));
        $this->assertSame(['PGDATA=/data'], $inner->imageEnvironment('postgres:16'));
    }

    public function test_shared_base_questions_go_to_the_bases(): void
    {
        $bases = $this->createMock(SharedBaseImages::class);
        $bases->expects($this->once())->method('ensurePhp')
            ->with('php:8.3-apache-bookworm', ['imagick'], ['ffmpeg'], ['imagick'])
            ->willReturn(['tag' => 'panelalpha/php:8.3-x', 'baked' => ['imagick']]);
        $bases->expects($this->once())->method('ensureRuby')->with('ruby:3.3-slim-bookworm', ['libpq-dev'])->willReturn('panelalpha/ruby:3.3');
        $bases->expects($this->once())->method('ensurePython')->with('python:3.12-slim', ['libpq-dev'])->willReturn(null);
        $inner = $this->inner(bases: $bases);

        $this->assertSame(
            ['tag' => 'panelalpha/php:8.3-x', 'baked' => ['imagick']],
            $inner->ensurePhpBaseImage('php:8.3-apache-bookworm', ['imagick'], ['ffmpeg'], ['imagick'])
        );
        $this->assertSame('panelalpha/ruby:3.3', $inner->ensureRubyBaseImage('ruby:3.3-slim-bookworm', ['libpq-dev']));
        $this->assertNull($inner->ensurePythonBaseImage('python:3.12-slim', ['libpq-dev']));
    }

    public function test_disk_questions_go_to_the_storage(): void
    {
        $storage = $this->createMock(StorageReclaim::class);
        $storage->expects($this->once())->method('reclaimIfNeeded');
        $storage->expects($this->once())->method('canAggressivelyReclaim')->willReturn(true);
        $storage->expects($this->once())->method('reclaim')->with(true);
        $storage->expects($this->once())->method('reclaimAfterDiskLimit');
        $storage->expects($this->once())->method('wipeDataRoot');
        $inner = $this->inner(storage: $storage);

        $inner->reclaimStorageIfNeeded();
        $this->assertTrue($inner->canAggressivelyReclaim());
        $inner->reclaimStorage(true);
        $inner->reclaimStorageAfterDiskLimit();
        $inner->wipeDataRoot();
    }

    private function inner(?ImageSeeding $seeding = null, ?SharedBaseImages $bases = null, ?StorageReclaim $storage = null): InnerDocker
    {
        $inner = new InnerDocker($this->createStub(Dind::class));
        foreach (['seeding' => $seeding, 'bases' => $bases, 'storage' => $storage] as $property => $collaborator) {
            if ($collaborator !== null) {
                (new \ReflectionProperty(InnerDocker::class, $property))->setValue($inner, $collaborator);
            }
        }

        return $inner;
    }
}

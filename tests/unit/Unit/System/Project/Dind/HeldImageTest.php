<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HeldImage;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Tests\TestCase;

/**
 * A push that builds a tag the target already has keeps the target's own
 * image under `<repository>:pre-push`, so a failed push can put it back.
 */
class HeldImageTest extends TestCase
{
    /** @var list<string> */
    private array $docker = [];

    /** @var list<string> */
    private array $present = [];

    public function test_an_image_the_account_has_is_held_under_its_pre_push_tag(): void
    {
        $this->present = ['panelalpha-live-app:latest'];

        $held = HeldImage::hold($this->account(), 'panelalpha-live-app:latest');

        $this->assertSame('panelalpha-live-app:pre-push', $held?->holdingTag());
        $this->assertSame(['tag panelalpha-live-app:latest panelalpha-live-app:pre-push'], $this->docker);
    }

    public function test_nothing_is_held_when_the_account_has_no_such_image(): void
    {
        $this->assertNull(HeldImage::hold($this->account(), 'panelalpha-live-app:latest'));
        $this->assertSame([], $this->docker);
    }

    public function test_putting_it_back_only_retags_the_name(): void
    {
        $this->present = ['panelalpha-live-app:latest'];
        $held = HeldImage::hold($this->account(), 'panelalpha-live-app:latest');

        $held?->putBack();

        $this->assertSame(
            [
                'tag panelalpha-live-app:latest panelalpha-live-app:pre-push',
                'tag panelalpha-live-app:pre-push panelalpha-live-app:latest',
            ],
            $this->docker
        );
    }

    public function test_releasing_it_only_drops_the_holding_tag(): void
    {
        $this->present = ['panelalpha-live-app:latest'];
        $held = HeldImage::hold($this->account(), 'panelalpha-live-app:latest');

        $held?->release();

        $this->assertSame(
            ['tag panelalpha-live-app:latest panelalpha-live-app:pre-push', 'rmi -f panelalpha-live-app:pre-push'],
            $this->docker
        );
    }

    public function test_a_registry_with_a_port_keeps_its_repository(): void
    {
        $this->present = ['registry.local:5000/team/app'];

        $this->assertSame('registry.local:5000/team/app:pre-push', HeldImage::hold($this->account(), 'registry.local:5000/team/app')?->holdingTag());
    }

    private function account(): Dind
    {
        $seeding = $this->createStub(ImageSeeding::class);
        $seeding->method('hasImage')->willReturnCallback(fn (string $image): bool => in_array($image, $this->present, true));
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('seeding')->willReturn($seeding);

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($this->system());
        $dind->method('innerDocker')->willReturn($inner);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return $dind;
    }

    private function system(): System
    {
        $record = function (string $command): void {
            $this->docker[] = $command;
        };

        return new class($record) extends System
        {
            /** @param \Closure(string): void $record */
            public function __construct(private \Closure $record) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = (array) $cmd;
                ($this->record)(implode(' ', array_slice($cmd, (int) array_search('dind', $cmd, true) + 2)));

                return '';
            }
        };
    }
}

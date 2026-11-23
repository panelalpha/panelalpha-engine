<?php

namespace Tests\Support;

use App\System;
use App\System\Filesystem;

/** A System that has never run an update, and records the updates it was asked to start instead of starting them. */
final class FakeUpdateCheckSystem extends System
{
    public bool $running = false;

    public int $started = 0;

    public function __construct() {}

    public function filesystem(): Filesystem
    {
        return new FakeUpdateCheckFilesystem;
    }

    public function isUpdateScriptRunning(): bool
    {
        return $this->running;
    }

    public function runUpdateScript(?string $licenseKey = null): void
    {
        $this->started++;
    }
}

<?php

namespace App\Console\Commands\Concerns;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayPosition;

trait ReadsOverlayOptions
{
    private function overlayOptions(): Overlay
    {
        $position = OverlayPosition::tryFrom((string) $this->option('position'))
            ?? throw new \InvalidArgumentException('--position must be one of: '.implode(', ', OverlayPosition::values()).'.');

        return new Overlay($this->overlayHtml(), $position);
    }

    private function overlayHtml(): string
    {
        $file = (string) $this->option('file');
        if ($file === '') {
            return (string) $this->option('html');
        }
        if (! is_readable($file)) {
            throw new \InvalidArgumentException("Cannot read '{$file}'.");
        }

        return (string) file_get_contents($file);
    }
}

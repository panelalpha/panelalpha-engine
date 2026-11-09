<?php

namespace Tests\Unit\DeployHook;

use App\System;
use App\System\Filesystem;

/** An engine whose only file is its certificate, or nothing at all, recording every read made through its filesystem. */
final class CertificateEngine extends System
{
    /** @var list<string> */
    public array $reads = [];

    public function __construct(public readonly ?string $pem = null)
    {
    }

    public function engineDirPath(): string
    {
        return '/opt/panelalpha/shared-hosting';
    }

    public function filesystem(): Filesystem
    {
        return new class ($this) extends Filesystem {
            public function __construct(private CertificateEngine $engine)
            {
                parent::__construct($engine);
            }

            public function fileExists(string $path): bool
            {
                return $this->engine->pem !== null && $path === $this->engine->engineDirPath() . '/crt/server.cert';
            }

            public function fileGetContents(string $path): string
            {
                $this->engine->reads[] = $path;

                return (string) $this->engine->pem;
            }
        };
    }
}

<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

/**
 * Everything the engine knows about one PHP deploy before it writes the compose file: what the
 * repository declared, and which shared image the host resolved for it.
 */
final class PhpBuild
{
    /**
     * @param  bool  $artisan  the app is Laravel, and boots like one
     * @param  bool  $waitForMysql  the account got a MySQL sidecar
     * @param  ?string  $baseImage  the shared PHP base the host resolved, or null for the stock php image
     * @param  string  $appRoot  application root inside the repository, '' when they are the same;
     *                           compose mounts it at /app, so nothing downstream needs to know it
     * @param  list<string>  $baseImageExtras  the extras beyond the baked set the base
     *                                         image was named with, so a pruned variant can be rebuilt
     * @param  list<string>  $baseImagePackages  the `system_packages:` set it was named with
     */
    public function __construct(
        public readonly ?string $composerJson = null,
        public readonly ?string $composerLock = null,
        public readonly bool $artisan = true,
        public readonly bool $waitForMysql = false,
        public readonly ?string $baseImage = null,
        public readonly string $appRoot = '',
        public readonly array $baseImageExtras = [],
        public readonly array $baseImagePackages = []
    ) {}

    public function composer(): ComposerManifest
    {
        return new ComposerManifest($this->composerJson, $this->composerLock);
    }

    /**
     * What this project needs compiled in, a question of whether the shared base already covers it.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return PhpExtensions::for($this->composer(), $this->artisan, $this->waitForMysql);
    }
}

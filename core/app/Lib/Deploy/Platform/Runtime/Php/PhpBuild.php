<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

/**
 * Everything the engine knows about one PHP deploy before it writes the
 * compose file: what the repository declared, and which shared image the host
 * resolved for it.
 *
 * Smaller than it was, because most of what it carried was Dockerfile input.
 * The frontend stage, the extension toolchain and the manifest's build command
 * all had to be known before an image could be written; none of them is
 * anyone's business here any more. Assets are compiled on the host, extensions
 * are baked into the shared base, and the build commands come straight from
 * the manifest to {@see \App\\System\\Project\Dind\HostCompile}.
 */
final class PhpBuild
{
    /**
     * @param  bool  $artisan  the app is Laravel, and boots like one
     * @param  bool  $waitForMysql  the account got a MySQL sidecar
     * @param  ?string  $baseImage  the shared PHP base the host resolved, or null
     *                              when it could not provide one and the stock php image is all
     *                              there is
     * @param  string  $appRoot  application root inside the repository, '' when
     *                           they are the same. The compose file mounts this subtree at /app,
     *                           so everything downstream -- composer, the document root, the
     *                           entrypoint -- keeps working on /app and needs to know nothing
     *                           about it.
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
     * What this project needs compiled in — which is now a question about
     * whether the shared base already covers it, not about what to install.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return PhpExtensions::for($this->composer(), $this->artisan, $this->waitForMysql);
    }
}

<?php

namespace App\System\Privileged;

final class BuildContainer
{
    /**
     * @param  array<string, string>  $mounts  container path => host path
     * @param  array<string, string>  $readOnlyMounts  container path => host path
     * @param  array<string, string>  $environment  name => value
     */
    public function __construct(
        public readonly string $user,
        public readonly string $memory,
        public readonly ?string $network,
        public readonly array $mounts,
        public readonly array $readOnlyMounts,
        public readonly string $workdir,
        public readonly array $environment,
        public readonly string $image,
    ) {}
}

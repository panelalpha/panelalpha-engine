<?php

namespace App\Lib\Deploy\Source;

/**
 * The transports a git command the engine runs may use, as one `GIT_ALLOW_PROTOCOL` policy that the clone,
 * the probe and every child git a submodule clone starts all inherit.
 */
final class GitTransport
{
    /** @return list<string> */
    public static function schemes(bool $withSsh = false): array
    {
        return $withSsh ? ['ssh', ...GitRepoInput::SCHEMES] : GitRepoInput::SCHEMES;
    }

    /**
     * @param  list<string>  $protocols
     * @return array<string, string>
     */
    public static function env(array $protocols): array
    {
        return ['GIT_ALLOW_PROTOCOL' => implode(':', $protocols)];
    }

    /** @param list<string> $protocols */
    public static function assignment(array $protocols): string
    {
        return 'GIT_ALLOW_PROTOCOL='.implode(':', $protocols);
    }
}

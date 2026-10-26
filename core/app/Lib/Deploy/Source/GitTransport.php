<?php

namespace App\Lib\Deploy\Source;

/**
 * The transports a git command the engine runs may use.
 *
 * One policy, so the clone, the probe and every submodule fetch cannot drift:
 * `GIT_ALLOW_PROTOCOL` is git's own form of `protocol.allow`, read by the
 * process and inherited by the child git a submodule clone starts.
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

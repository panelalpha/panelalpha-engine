<?php

namespace App\Lib\Deploy\Source;

/**
 * Submodule URLs over ssh (`git@host:org/lib.git`, `ssh://git@host/org/lib.git`) read over https
 * instead, for a project with no deploy key: ssh then has no key to offer, and git's
 * `-c` reaches the clone of every nested submodule too.
 */
final class SshSubmoduleRewrite
{
    private const SCP_URL = '/^\s*url\s*=\s*([\w.-]+@([\w.-]+):)/m';

    private const SSH_URL = '/^\s*url\s*=\s*(ssh:\/\/(?:[\w.-]+@)?([\w.-]+)(?::\d+)?\/)/mi';

    /**
     * `-c url.https://<host>/.insteadOf=<prefix>` for every ssh prefix the `.gitmodules` text names.
     *
     * @return list<string>
     */
    public static function configArgs(string $gitmodules): array
    {
        $args = [];
        foreach (self::prefixes($gitmodules) as $prefix => $host) {
            array_push($args, '-c', "url.https://{$host}/.insteadOf={$prefix}");
        }

        return $args;
    }

    /** @return array<string, string> ssh prefix => host */
    private static function prefixes(string $gitmodules): array
    {
        $prefixes = [];
        foreach ([self::SCP_URL, self::SSH_URL] as $pattern) {
            preg_match_all($pattern, $gitmodules, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $prefixes[$match[1]] = strtolower($match[2]);
            }
        }

        return $prefixes;
    }
}

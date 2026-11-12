<?php

namespace App\System\Privileged;

/** The webserver reload, run on the host once the request is answered. */
final class RootWebserver
{
    private const AREA = 'webserver';

    private const COMPOSE_FILE = '~\A/[A-Za-z0-9_/.-]+/docker-compose\.yml\z~';

    /**
     * With $rebindIpListeners nginx restarts to bind new listen addresses. Null for a webserver with no reload.
     *
     * @return ?list<string>
     */
    public function queueReload(string $composeFile, string $webserver, bool $rebindIpListeners): ?array
    {
        $kind = self::kind($webserver, $rebindIpListeners);
        if ($kind === null) {
            return null;
        }

        return Root::action(self::AREA, __FUNCTION__, [self::composeFile($composeFile), $kind], true);
    }

    private static function composeFile(string $composeFile): string
    {
        if (preg_match(self::COMPOSE_FILE, $composeFile) !== 1 || str_contains($composeFile, '/../')) {
            throw InvalidArgument::of('compose file', $composeFile);
        }

        return $composeFile;
    }

    private static function kind(string $webserver, bool $rebindIpListeners): ?string
    {
        return match ($webserver) {
            'litespeed', 'openlitespeed' => 'litespeed',
            'nginx', 'nginx-proxy' => $rebindIpListeners ? 'nginx-rebind' : 'nginx',
            'apache' => 'apache',
            default => null,
        };
    }
}

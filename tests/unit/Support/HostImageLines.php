<?php

namespace Tests\Support;

/**
 * A pa-root that answers the broker's host image lines with the docker command lines they print the same thing as,
 * so a test's fake `docker` stands in for the host's daemon. Every other request goes to pa-root-local.
 */
final class HostImageLines
{
    private static ?string $broker = null;

    public static function install(string $dir, string $name = 'pa-root'): string
    {
        $path = $dir.'/'.$name;
        file_put_contents($path, str_replace('__FALLBACK__', var_export(LocalRoot::client(), true), self::SCRIPT));
        chmod($path, 0755);
        self::$broker = $path;

        return $path;
    }

    /** The script with every broker request in it answered by the installed pa-root. */
    public static function line(string $script): string
    {
        return str_replace(LocalRoot::client(), (string) self::$broker, LocalRoot::line($script));
    }

    private const SCRIPT = <<<'SH'
        #!/bin/bash
        fallback=__FALLBACK__
        original=("$@")
        if [ "${1-}" = --host ]; then shift; fi
        action=$1; shift
        [ "${1-}" = -- ] && shift
        case "$action" in
          lines.inspectImage) exec docker image inspect -- "$1" ;;
          lines.imageLabel) exec docker image inspect --format "{{ index .Config.Labels \"$2\" }}" -- "$1" ;;
          lines.pullQuietly) exec docker pull -q -- "$1" ;;
          lines.pushQuietly) exec docker push -q -- "$1" ;;
          lines.tag) exec docker tag -- "$1" "$2" ;;
          lines.removeImage) exec docker image rm -- "$1" ;;
          lines.isRunning) exec docker inspect --format '{{.State.Running}}' -- "$1" ;;
          lines.buildFromStdin) p=; [ "$2" = pull ] && p=--pull; exec docker build $p -t "$1" - ;;
          lines.buildQuietly) exec docker build -q -t "$1" "$2" ;;
          probes.hostImage) exec docker image inspect -- "$1" ;;
          host.refreshPublicImage) docker pull -q -- "$1" >/dev/null 2>&1; exit 0 ;;
          *) exec "$fallback" "${original[@]}" ;;
        esac
        SH;
}

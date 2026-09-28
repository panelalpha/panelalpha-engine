<?php

namespace App\Lib\Deploy\Compose;

/**
 * Workstation-only services with no place in a deployment: mail catchers,
 * asset dev-servers, browser drivers, tunnels — matched by name or by the
 * dev-server command, since many appear under a project-specific name.
 */
final class DevServices
{
    /** @var list<string> */
    private const NAMES = [
        'vite',
        'webpack',
        'webpack-dev-server',
        'webpacker',
        'js-host',
        'css-host',
        'mailhog',
        'mailpit',
        'mailcatcher',
        'selenium',
        'chrome',
        'chromium',
        'playwright',
        'cypress',
        'storybook',
        'ngrok',
        // Administration and observability consoles: attached to a stack, never
        // the thing the stack is for, and several hand out the database they point at.
        'adminer',
        'phpmyadmin',
        'pgadmin',
        'pgadmin4',
        'keycloak',
        'grafana',
        'loki',
        'tempo',
        'jaeger',
        'zipkin',
        'prometheus',
        'kibana',
        'swagger-ui',
        // Debug and mail-catching tools a workstation compose runs beside the app.
        'buggregator',
        'maildev',
        'smtp4dev',
        'mongo-express',
        'redis-commander',
        'redisinsight',
        'dozzle',
    ];

    /**
     * Tools recognised by their image as well, since a compose file names the
     * service whatever it likes (`mail: axllent/mailpit`, `debug:
     * ghcr.io/buggregator/server`). Only tools no deployment ever needs.
     *
     * @var list<string>
     */
    private const IMAGES = [
        'mailhog', 'mailpit', 'mailcatcher', 'maildev', 'smtp4dev',
        'buggregator', 'selenium', 'playwright', 'cypress', 'ngrok',
    ];

    private const DEV_COMMAND_PATTERN = '/\b(vite|webpack-dev-server|storybook)\s+(dev|serve)\b/';

    /**
     * @param array<string, mixed> $service
     */
    public static function isDevSidecar(string $name, array $service): bool
    {
        if (in_array(strtolower($name), self::NAMES, true) || self::isDevToolImage($service['image'] ?? null)) {
            return true;
        }

        $command = ComposeCommand::asString($service['command'] ?? null);

        return $command !== '' && preg_match(self::DEV_COMMAND_PATTERN, $command) === 1;
    }

    /** Any path segment of the image repository names a tool: `ghcr.io/buggregator/server`. */
    private static function isDevToolImage(mixed $image): bool
    {
        if (!is_string($image) || trim($image) === '') {
            return false;
        }
        $repository = explode('@', strtolower(trim($image)), 2)[0];
        foreach (explode('/', $repository) as $segment) {
            $segment = explode(':', $segment, 2)[0];
            foreach (self::IMAGES as $tool) {
                if ($segment === $tool || str_starts_with($segment, $tool . '-')) {
                    return true;
                }
            }
        }

        return false;
    }
}

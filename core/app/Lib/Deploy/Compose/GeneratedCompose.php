<?php

namespace App\Lib\Deploy\Compose;

use Symfony\Component\Yaml\Yaml;

/**
 * The compose file the engine writes: the application service, whatever
 * sidecars the decision brought with it, and the volumes they need.
 */
final class GeneratedCompose
{
    public const APP_SERVICE = 'app';

    /**
     * The image the application service runs, read back out of a file this
     * class produced — here because this class decided where that image sits.
     *
     * Null for anything unparseable or without an app service; callers read
     * that as "no better answer than the one I already had".
     */
    public static function appImage(string $yaml): ?string
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (\Exception $e) {
            return null;
        }

        $service = $parsed['services'][self::APP_SERVICE] ?? null;
        // A service that builds has no pullable runtime image. Its `image:` is
        // only the local tag the build is given — there is nothing at that
        // name on any registry — and reading it back would have the snapshot
        // provision a tag only this deploy can create, so the pre-pull it
        // drives asks Docker Hub for the project's own build and is refused.
        // Null here means "no better answer than the one already frozen",
        // which for a build service is the honest one.
        if (!is_array($service) || isset($service['build'])) {
            return null;
        }

        $image = $service['image'] ?? null;

        return is_string($image) && trim($image) !== '' ? trim($image) : null;
    }

    /**
     * The label key every generated service carries, and the marker
     * {@see ComposeFileInspector} recognises a generated file by.
     *
     * The value says which generator wrote it — 'railpack', 'dockerfile',
     * 'framework-db'. The key is what must not drift: seven places wrote it
     * out by hand, and renaming it anywhere but all of them at once would
     * leave the inspector treating engine output as a file the customer
     * wrote.
     */
    public const LABEL = 'panelalpha.generated';

    private const INLINE_DEPTH = 4;

    private const INDENT = 2;

    /**
     * @param array<string, mixed> $appService
     * @param array<string, mixed> $decision
     */
    public static function render(array $appService, array $decision = []): string
    {
        // Defaulted here rather than in each generator, because forgetting it
        // fails silently: Docker's own default is `no`, so the app comes up
        // on the deploy that created it and never again. staticNginx() was
        // the one caller that forgot, and its sites stayed down after a host
        // reboot while every other platform's came back.
        $appService['restart'] ??= 'unless-stopped';
        // The names the project's own kept services reach the application by.
        // The file's app service was dropped and this one replaced it under
        // the name `app`, so a proxy in front — which names the old service in
        // its own nginx config and not through `depends_on` — would otherwise
        // refuse to start with `host not found in upstream`. Docker's network
        // aliases are put on the app service here, in the one place every
        // generator goes through, because the alternative is rewriting a
        // configuration we do not own. {@see ServiceAliases}
        $aliases = ServiceAliases::fromDecision($decision);
        if ($aliases !== []) {
            $appService['networks'] = ServiceAliases::withAliases($appService['networks'] ?? null, $aliases);
        }
        $sidecars = self::sidecars($decision);
        if (is_array($appService['depends_on'] ?? null) && array_is_list($appService['depends_on'])) {
            $appService['depends_on'] = self::dependsOn($appService['depends_on'], $sidecars);
        }

        $compose = ['services' => [self::APP_SERVICE => $appService] + $sidecars];
        $volumes = $decision['volumes'] ?? null;
        if (is_array($volumes)) {
            $compose['volumes'] = $volumes;
        }

        return Yaml::dump($compose, self::INLINE_DEPTH, self::INDENT);
    }

    /**
     * The long form, so the app waits for a sidecar that can say it is ready.
     * The short form only waits for the container to start, and an app that
     * migrates on boot then races its own database (engine#187).
     *
     * @param list<mixed> $names
     * @param array<string, array<string, mixed>> $sidecars
     * @return array<string, array{condition: string}>
     */
    private static function dependsOn(array $names, array $sidecars): array
    {
        $depends = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $depends[$name] = [
                'condition' => self::hasHealthcheck($sidecars[$name] ?? []) ? 'service_healthy' : 'service_started',
            ];
        }

        return $depends;
    }

    /** @param array<string, mixed> $service */
    private static function hasHealthcheck(array $service): bool
    {
        $check = $service['healthcheck'] ?? null;
        if (!is_array($check) || ($check['disable'] ?? false) === true) {
            return false;
        }
        $test = $check['test'] ?? null;

        return $test !== null && $test !== [] && $test !== ['NONE'] && $test !== 'NONE';
    }

    /**
     * @param array<string, mixed> $decision
     * @return array<string, array<string, mixed>>
     */
    private static function sidecars(array $decision): array
    {
        $sidecars = [];
        foreach ((array) ($decision['sidecars'] ?? []) as $name => $sidecar) {
            if (is_string($name) && $name !== '' && is_array($sidecar)) {
                $sidecars[$name] = $sidecar;
            }
        }

        return $sidecars;
    }
}

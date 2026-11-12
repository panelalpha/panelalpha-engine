<?php

namespace Tests\Unit\System\Privileged;

use App\Lib\Deploy\CacheManager\PublishedBaseImage;
use App\Lib\Deploy\Dind\AccountImage;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\System\Privileged;
use Tests\Support\TestNetwork;

/** Broker requests for the cases in scripts-cases.json, which the broker's own tests plan. */
final class ScriptsLinesBrokerCases
{
    public const FILE = '/dockerfiles/root-broker/tests/scripts-cases.json';

    private const ENGINE = '/opt/panelalpha/shared-hosting';

    private const ACCOUNT_FILE = self::ENGINE.'/users/alice/docker-compose.yml';

    private const BASE = 'panelalpha/php:8.3-cli-bookworm-pa1234abcd';

    /**
     * @return list<array{name: string, host: bool, broker: list<string>|string}>
     */
    public static function all(): array
    {
        $cases = [];
        foreach (self::calls() as $name => [$call, $host]) {
            $cases[] = ['name' => $name, 'host' => $host, 'broker' => $host ? Privileged::onHost($call()) : $call()];
        }

        return $cases;
    }

    /** @return iterable<string, array{\Closure(): (list<string>|string), bool}> */
    private static function calls(): iterable
    {
        yield from self::scripts();
        yield from self::dockerLines();
        yield from self::composeLines();
        yield from self::fileLines();
    }

    /** @return iterable<string, array{\Closure(): (list<string>|string), bool}> */
    private static function scripts(): iterable
    {
        $s = static fn () => Privileged::scripts();

        yield 'removeStartingWith' => [fn () => $s()->removeStartingWith(self::ENGINE.'/webserver-config/apache/vhosts/a.example.com.conf'), false];
        yield 'removeStartingWith, litespeed' => [fn () => $s()->removeStartingWith(self::ENGINE.'/webserver-config/litespeed/vhosts/b.example.com.xml'), false];
        yield 'isRealDirectory' => [fn () => $s()->isRealDirectory('/home/alice/public_html'), false];
        yield 'makeHomeEntry' => [fn () => $s()->makeHomeEntry('/home/alice/public_html', '0755', '1004:1004'), false];
        yield 'makeHomeEntry, private' => [fn () => $s()->makeHomeEntry('/home/alice/.panelalpha', '0700', '1004:1004'), false];
        yield 'composeImageLines' => [fn () => DindAccountCleanup::otherAccountComposeImagesArgv('/home', 'alice'), false];
        yield 'publishBuildCache' => [
            fn () => $s()->publishBuildCache(self::ENGINE.'/data/build-cache/0a1b2c3d4e5f/out-1a2b3c4d', TestNetwork::LOOPBACK.':5000/buildcache/github.com/acme/app:main'),
            false,
        ];
    }

    /** @return iterable<string, array{\Closure(): string, bool}> */
    private static function dockerLines(): iterable
    {
        $d = static fn () => Privileged::dockerLines();
        $published = 'ghcr.io/panelalpha/php:8.3-cli-bookworm-pa1234abcd';

        yield 'inspectImage' => [fn () => $d()->inspectImage(self::BASE), false];
        yield 'imageLabel' => [fn () => $d()->imageLabel($published, PublishedBaseImage::DOCKERFILE_LABEL), false];
        yield 'pullQuietly' => [fn () => $d()->pullQuietly(AccountImage::REF), false];
        yield 'pushQuietly' => [fn () => $d()->pushQuietly(TestNetwork::LOOPBACK.':5000/'.self::BASE), false];
        yield 'tag' => [fn () => $d()->tag($published, self::BASE), false];
        yield 'removeImage' => [fn () => $d()->removeImage($published), false];
        yield 'buildFromStdin' => [fn () => $d()->buildFromStdin(self::BASE, true), false];
        yield 'buildFromStdin, no pull' => [fn () => $d()->buildFromStdin(self::BASE, false), false];
        yield 'buildQuietly' => [fn () => $d()->buildQuietly(AccountImage::REF, self::ENGINE.'/templates/user/dind/project'), false];
        yield 'isRunning' => [fn () => $d()->isRunning('panelalpha-cache-registry-writer'), false];
    }

    /** @return iterable<string, array{\Closure(): string, bool}> */
    private static function composeLines(): iterable
    {
        $c = static fn (string $file = self::ACCOUNT_FILE) => Privileged::composeLines($file);

        yield 'composePull' => [fn () => $c()->pull(), false];
        yield 'composeBuild' => [fn () => $c()->build(), false];
    }

    /** @return iterable<string, array{\Closure(): string, bool}> */
    private static function fileLines(): iterable
    {
        $f = static fn () => Privileged::fileLines();

        yield 'removeScripts' => [fn () => $f()->removeScripts(self::ENGINE.'/users/alice/entrypoint-init.d'), false];
        yield 'removeScriptsIfAny' => [fn () => $f()->removeScriptsIfAny(self::ENGINE.'/users/alice/entrypoint.d'), false];
        yield 'removeProxyRuleConfigsIfAny' => [fn () => $f()->removeProxyRuleConfigsIfAny(self::ENGINE.'/webserver-config/nginx-proxy/vhosts'), false];
        yield 'emptyDirectory' => [fn () => $f()->emptyDirectory(self::ENGINE.'/users/alice/apache-sites'), false];
    }
}

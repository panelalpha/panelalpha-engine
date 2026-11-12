<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged\Arguments;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\Root;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

class ArgumentsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'path' => ['path', '/home/alice/project'];
        yield 'path with a space and a trailing slash' => ['path', '/home/alice/my site/'];
        yield 'path with dots in a name' => ['path', '/home/alice/.project-next.1'];
        yield 'container' => ['name', 'panelalpha-cache-registry'];
        yield 'service' => ['name', 'sites-http'];
        yield 'compose project name' => ['name', 'alice_app.1'];
        yield 'username' => ['username', 'alice'];
        yield 'older username with an underscore' => ['username', 'dind_test-01'];
        yield 'owner' => ['owner', 'www-data:www-data'];
        yield 'numeric owner' => ['owner', '1004:1004'];
        yield 'owner without group' => ['owner', 'nobody'];
        yield 'octal mode' => ['mode', '0755'];
        yield 'short octal mode' => ['mode', '600'];
        yield 'symbolic mode' => ['mode', 'a+r'];
        yield 'compound symbolic mode' => ['mode', 'ugo-rwx,u+rwX,go+rX'];
        yield 'image' => ['image', 'composer:2'];
        yield 'image with registry and port' => ['image', TestNetwork::LOOPBACK.':5000/panelalpha/php:8.3-cli-bookworm-pa1234abcd'];
        yield 'image on a named registry' => ['image', 'panelalpha-cache-registry:5000/acme/base:1.2'];
        yield 'image with digest' => ['image', 'ghcr.io/acme/app@sha256:'.str_repeat('a', 64)];
        yield 'image ID' => ['image', 'sha256:'.str_repeat('0', 64)];
        yield 'short image ID' => ['image', '0123456789ab'];
        yield 'apache module' => ['apacheModule', 'proxy_fcgi'];
        yield 'operand' => ['operand', 'until=3600s'];
        yield 'hostname' => ['hostname', 'engine.example.com'];
        yield 'mount path with a space' => ['mountPath', '/home/alice/my project'];
        yield 'environment variable name' => ['environmentName', 'npm_config_cache'];
        yield 'build argument name' => ['environmentName', 'PHP_VERSION'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'relative path' => ['path', 'home/alice'];
        yield 'path climbing out' => ['path', '/home/alice/../bob'];
        yield 'path ending in ..' => ['path', '/home/alice/..'];
        yield 'path with a newline' => ['path', "/home/alice\n/etc"];
        yield 'path with NUL' => ['path', "/home/alice\0"];
        yield 'name that is an option' => ['name', '-v'];
        yield 'name with a space' => ['name', 'a b'];
        yield 'name with a semicolon' => ['name', 'web;id'];
        yield 'username with uppercase' => ['username', 'Alice'];
        yield 'username with a slash' => ['username', 'alice/../root'];
        yield 'owner with a space' => ['owner', 'root root'];
        yield 'owner that is an option' => ['owner', '--from=root'];
        yield 'mode with a path' => ['mode', '755 /etc'];
        yield 'mode that is an option' => ['mode', '-R'];
        yield 'image with a space' => ['image', 'nginx latest'];
        yield 'image that is an option' => ['image', '--all'];
        yield 'image with uppercase path' => ['image', 'Nginx'];
        yield 'apache module with a command' => ['apacheModule', 'rewrite; sudo id'];
        yield 'operand that is an option' => ['operand', '--delete'];
        yield 'operand with a line break' => ['operand', "x\ny"];
        yield 'two hostnames' => ['hostname', 'a.example.com,DNS:evil.example'];
        yield 'hostname that is an option' => ['hostname', '-x.example.com'];
        yield 'wildcard where none is allowed' => ['hostname', '*.example.com'];
        yield 'mount path with a colon' => ['mountPath', '/home/alice:/etc'];
        yield 'relative mount path' => ['mountPath', 'home/alice'];
        yield 'mount path climbing out' => ['mountPath', '/home/alice/../bob'];
        yield 'environment variable name that is an option' => ['environmentName', '-e'];
        yield 'environment variable name with a space' => ['environmentName', 'A B'];
        yield 'environment variable name with a value' => ['environmentName', 'A=1'];
    }

    #[DataProvider('accepted')]
    public function test_a_safe_value_passes_unchanged(string $kind, string $value): void
    {
        $this->assertSame($value, Arguments::$kind($value));
    }

    #[DataProvider('refused')]
    public function test_a_value_a_command_could_misread_is_refused(string $kind, string $value): void
    {
        $this->expectException(InvalidArgument::class);

        Arguments::$kind($value);
    }

    public function test_every_path_and_image_in_a_list_is_checked(): void
    {
        $this->assertSame(['/a', '/b'], Arguments::paths(['/a', '/b']));
        $this->assertSame(['nginx:1', 'redis:7'], Arguments::images(['nginx:1', 'redis:7']));

        $this->expectException(InvalidArgument::class);
        Arguments::paths(['/a', 'b']);
    }

    public function test_a_wildcard_hostname_passes_only_when_asked_for(): void
    {
        $this->assertSame('*.example.com', Arguments::hostname('*.example.com', wildcard: true));

        $this->expectException(InvalidArgument::class);
        Arguments::hostname('*.*.example.com', wildcard: true);
    }

    public function test_a_refusal_names_the_value(): void
    {
        $this->expectExceptionMessage('Refusing Apache module "rewrite; sudo id" for a root command');

        Arguments::apacheModule('rewrite; sudo id');
    }

    public function test_a_line_quotes_every_word_that_is_not_plainly_safe(): void
    {
        $this->assertSame(
            "/usr/local/lib/panelalpha/pa-root compose.restart -- '/opt/my engine/docker-compose.yml' 'sites-http; id'",
            Root::actionLine('compose', 'restart', ['/opt/my engine/docker-compose.yml', 'sites-http; id'])
        );
        $this->assertSame(
            '/usr/local/lib/panelalpha/pa-root lines.removeScriptsIfAny -- /home/alice/entrypoint.d/',
            Root::actionLine('lines', 'removeScriptsIfAny', ['/home/alice/entrypoint.d/'])
        );
        $this->assertSame("/usr/local/lib/panelalpha/pa-root lines.buildFromStdin -- a:1 ''", Root::actionLine('lines', 'buildFromStdin', ['a:1', '']));
        $this->assertSame("/usr/local/lib/panelalpha/pa-root lines.tag -- 'it'\\''s' b:1", Root::actionLine('lines', 'tag', ["it's", 'b:1']));
    }
}

<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\Php\AccountUserFiles;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;

/**
 * The `app` service for a platform the engine builds itself, shaped as the Dockerfile generators decide: stock nginx
 * over a static build's output, stock Node over a host-compiled Nitro bundle, or the image the generated Dockerfile builds.
 */
final class FrameworkService
{
    private const NGINX_HOST_PORT = '8080:80';

    private const WORKDIR = '/app';

    private readonly bool $isNginx;

    private readonly bool $isPhp;

    private readonly bool $isStandaloneNode;

    private readonly bool $isHostRunProject;

    /**
     * @param  array<string, mixed>  $decision
     */
    public function __construct(
        private readonly array $decision,
        private readonly int $port,
        private readonly ?string $publicUrl = null
    ) {
        $runtime = $decision['runtime'] ?? PlatformManifest::RUNTIME_NODE;
        $this->isNginx = $runtime === PlatformManifest::RUNTIME_NGINX;
        $this->isPhp = $runtime === PlatformManifest::RUNTIME_PHP;
        $this->isStandaloneNode = StandaloneNodeServe::isStandaloneStrategy(
            is_string($decision['strategy'] ?? null) ? $decision['strategy'] : null
        );
        $this->isHostRunProject = HostRunProject::isStrategy(
            is_string($decision['strategy'] ?? null) ? $decision['strategy'] : null
        );
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    public static function for(array $decision, int $port, ?string $publicUrl = null): array
    {
        return (new self($decision, $port, $publicUrl))->build();
    }

    /**
     * `extra_hosts` pins names the app's nested Docker cannot resolve to the addresses the engine resolved, such as the
     * account's MySQL server on a compose network several NATs away.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $service = array_merge($this->base(), $this->shape());
        $depends = ComposeValues::stringList($this->decision['depends_on'] ?? null);
        if ($depends !== []) {
            $service += ['depends_on' => $depends];
        }
        $extraHosts = ComposeValues::stringList($this->decision['extra_hosts'] ?? null);

        return $extraHosts === [] ? $service : $service + ['extra_hosts' => $extraHosts];
    }

    /**
     * @return array<string, mixed>
     */
    private function base(): array
    {
        return [
            'restart' => 'unless-stopped',
            'labels' => [GeneratedCompose::LABEL => 'framework-recipe'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(): array
    {
        return match (true) {
            $this->isNginx => $this->staticSite(),
            $this->isStandaloneNode => $this->standaloneNode() + $this->runtimeEnvironment(),
            $this->isHostRunProject => $this->mountedProject() + $this->runtimeEnvironment(),
            $this->isPhp => $this->mountedPhp() + $this->runtimeEnvironment(),
            default => $this->builtImage() + $this->runtimeEnvironment(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function staticSite(): array
    {
        $output = NodeRuntime::safeOutputDir($this->decision['output_directory'] ?? null);

        return [
            'image' => Images::NGINX_IMAGE,
            'ports' => [self::NGINX_HOST_PORT],
            'volumes' => [
                './'.$output.':/usr/share/nginx/html:ro',
                './'.NginxConfig::FILENAME.':/etc/nginx/conf.d/default.conf:ro',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function standaloneNode(): array
    {
        $image = trim((string) ($this->decision['image'] ?? '')) ?: NodeRuntime::defaultImage();

        return [
            'image' => $image,
            'working_dir' => self::WORKDIR,
            'command' => StandaloneNodeServe::startCommand($image),
            'ports' => ["{$this->port}:{$this->port}"],
            'volumes' => $this->standaloneVolumes(),
        ];
    }

    /**
     * @return list<string>
     */
    private function standaloneVolumes(): array
    {
        $volumes = ['./'.StandaloneNodeServe::FILENAME.':/app/'.StandaloneNodeServe::FILENAME.':ro'];
        foreach (StandaloneNodeServe::volumeSources() as $dir) {
            $volumes[] = './'.$dir.':/app/'.$dir.':ro';
        }
        $volumes[] = './node_modules:/app/node_modules:ro';

        return $volumes;
    }

    /**
     * Run like PHP: one stock image, the whole project mounted read-write (`next start`, unlike Nitro, is not self-contained; Next.js writes
     * `.next/cache`); the staged entrypoint (install and upgrade first), else a JSON argv as PID 1, else `sh -c`; `$` doubled as in shellCommand().
     *
     * @return array<string, mixed>
     */
    private function mountedProject(): array
    {
        $service = [
            'image' => trim((string) ($this->decision['image'] ?? '')) ?: NodeRuntime::defaultImage(),
            'working_dir' => self::WORKDIR,
            'volumes' => [$this->appRoot().':'.self::WORKDIR],
            'ports' => ["{$this->port}:{$this->port}"],
        ];

        $provision = str_replace('$', '$$', HostRunProject::packageManagerPrefix($this->decision));
        $entrypoint = trim((string) ($this->decision['entrypoint'] ?? ''));
        if ($entrypoint !== '') {
            $service['command'] = ['sh', '-c', $provision.'exec '.self::WORKDIR.'/'.$entrypoint];

            return $service + $this->mountedIdentity();
        }

        $command = HostRunProject::startCommand($this->decision);
        if ($command !== null) {
            $argv = json_decode($command, true);
            $service['command'] = is_array($argv) && array_is_list($argv) && $argv !== []
                ? array_map('strval', $argv)
                : ['sh', '-c', $provision.self::shellCommand($command)];
        }

        return $service + $this->mountedIdentity();
    }

    /**
     * Same reason as PHP: /app is the customer's directory, so what the application writes there has to belong to them
     * or their SFTP cannot touch it afterwards.
     *
     * @return array<string, string>
     */
    private function mountedIdentity(): array
    {
        $identity = trim((string) ($this->decision['user'] ?? ''));

        return $identity === '' ? [] : ['user' => $identity];
    }

    /**
     * A start command for `sh -c` in compose (compose's string form would leave a glob unexpanded), `exec`-prefixed to keep
     * PID 1 only when it is one command, not a script; `$` doubled, since compose interpolates `$var` before the shell runs.
     */
    public static function shellCommand(string $command): string
    {
        $compound = preg_match('/(;|&&|\|\||\n)/', $command) === 1
            || str_starts_with(trim($command), 'exec ');

        return str_replace('$', '$$', $compound ? $command : 'exec '.$command);
    }

    /**
     * The shared PHP base, pulled once per host, with `~/project` mounted as the live document root; nothing is built. It runs as
     * the account, so what it writes to /app (and its stdio, for Apache's logs) stays theirs; {@see AccountUserFiles} names that uid.
     *
     * @return array<string, mixed>
     */
    private function mountedPhp(): array
    {
        $service = [
            'image' => $this->phpImage(),
            'working_dir' => self::WORKDIR,
            'volumes' => [$this->appRoot().':'.self::WORKDIR],
            'ports' => ["{$this->port}:{$this->port}"],
        ];
        $identity = trim((string) ($this->decision['user'] ?? ''));
        if ($identity !== '') {
            $service['user'] = $identity;
        }
        $cache = trim((string) ($this->decision['composer_cache_dir'] ?? ''));
        if ($cache !== '') {
            $service['volumes'][] = $cache.':'.PhpBaseImage::COMPOSER_CACHE_DIR;
        }
        if (($this->decision['account_user_files'] ?? false) === true) {
            array_push($service['volumes'], ...AccountUserFiles::volumes());
        }

        return $service;
    }

    /**
     * The manifest's `app_root` to mount as /app, or the whole checkout; validated rather than trusted, since it becomes
     * a bind-mount source and must not climb out of the checkout.
     */
    private function appRoot(): string
    {
        return AppRoot::mount($this->decision);
    }

    private function phpImage(): string
    {
        $image = trim((string) ($this->decision['image'] ?? ''));

        return $image !== '' ? $image : PhpRuntime::imageTag(PhpRuntime::defaultMinor());
    }

    /**
     * @return array<string, mixed>
     */
    private function builtImage(): array
    {
        return [
            'build' => ['context' => '.', 'dockerfile' => DockerfileBuilder::FILENAME],
            'ports' => ["{$this->port}:{$this->port}"],
        ];
    }

    /**
     * For the shapes that run the application: `env_file` carries the repository's own secrets, the generated values override
     * a template's localhost placeholders, and a `path_prefix_keys` entry is left to the file, since it is a sub-path.
     *
     * @return array<string, mixed>
     */
    private function runtimeEnvironment(): array
    {
        return [
            'env_file' => ['.env'],
            'environment' => array_merge(
                [
                    'HOST' => '0.0.0.0',
                    'HOSTNAME' => '0.0.0.0',
                    'PORT' => (string) $this->port,
                ],
                array_diff_key(
                    PublicUrlEnvironment::for($this->publicUrl),
                    array_flip(ComposeValues::stringList($this->decision['path_prefix_keys'] ?? null))
                ),
                ComposeValues::stringMap($this->decision['env'] ?? null)
            ),
        ];
    }
}

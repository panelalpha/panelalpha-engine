<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;

/**
 * The `app` service for a platform the engine builds itself.
 *
 * Three shapes, and which one applies is the same question the Dockerfile
 * generators ask: a static build is served by stock nginx from the output
 * directory, a Nitro build by stock Node from the server bundle the host
 * compiled, and everything else by the image built from the generated
 * Dockerfile.
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
     * @param array<string, mixed> $decision
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
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public static function for(array $decision, int $port, ?string $publicUrl = null): array
    {
        return (new self($decision, $port, $publicUrl))->build();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $service = array_merge($this->base(), $this->shape());
        $depends = ComposeValues::stringList($this->decision['depends_on'] ?? null);
        if ($depends !== []) {
            $service += ['depends_on' => $depends];
        }
        // Names the app's own nested Docker cannot resolve, pinned to the
        // addresses the engine resolved for it — the account's MySQL server
        // lives on a compose network several NATs away.
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
                './' . $output . ':/usr/share/nginx/html:ro',
                './' . NginxConfig::FILENAME . ':/etc/nginx/conf.d/default.conf:ro',
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
        $volumes = ['./' . StandaloneNodeServe::FILENAME . ':/app/' . StandaloneNodeServe::FILENAME . ':ro'];
        foreach (StandaloneNodeServe::volumeSources() as $dir) {
            $volumes[] = './' . $dir . ':/app/' . $dir . ':ro';
        }
        $volumes[] = './node_modules:/app/node_modules:ro';

        return $volumes;
    }

    /**
     * Any project, the same way PHP runs: our stock image, the account's
     * directory mounted, nothing built.
     *
     * Node, Python, Go, Rust and Java all arrive here. What they have in
     * common is that their build leaves everything the app needs *in the
     * project* — node_modules, a .venv, `./app`, `target/*.jar` — so the
     * image only has to supply the runtime, and one shared image can serve
     * every account on the host.
     *
     * The difference from {@see standaloneNode()} is what the build produced.
     * Nitro emits a self-contained server bundle, so only `.output` and
     * `node_modules` need mounting and they can be read-only. `next start` is
     * not self-contained — it needs `node_modules`, the build output and
     * `package.json` — so the whole project is mounted, exactly as PHP mounts
     * its document root.
     *
     * Read-write, and that is not an oversight: Next.js writes `.next/cache`
     * at runtime for ISR and image optimisation, and a read-only mount turns
     * those into 500s at request time rather than at deploy time.
     *
     * @return array<string, mixed>
     */
    private function mountedProject(): array
    {
        $service = [
            'image' => trim((string) ($this->decision['image'] ?? '')) ?: NodeRuntime::defaultImage(),
            'working_dir' => self::WORKDIR,
            'volumes' => [$this->appRoot() . ':' . self::WORKDIR],
            'ports' => ["{$this->port}:{$this->port}"],
        ];

        // The staged entrypoint runs the platform's install and upgrade
        // commands before the serve one, so where a project has it, it *is*
        // the command. Running the start command directly is what left a
        // Django project's migrations unapplied.
        // Doubled `$` for the same reason as shellCommand().
        $provision = str_replace('$', '$$', HostRunProject::packageManagerPrefix($this->decision));
        $entrypoint = trim((string) ($this->decision['entrypoint'] ?? ''));
        if ($entrypoint !== '') {
            $service['command'] = ['sh', '-c', $provision . 'exec ' . self::WORKDIR . '/' . $entrypoint];

            return $service + $this->mountedIdentity();
        }

        $command = HostRunProject::startCommand($this->decision);
        if ($command !== null) {
            // A recipe that already settled on an argv says so as JSON -- the
            // Ruby one picks between falcon, puma, unicorn, rails server and
            // rackup and hands back an exec form. Passing that straight
            // through keeps the application as PID 1 without a shell in the
            // way at all, which is strictly better than the sh -c below.
            $argv = json_decode($command, true);
            $service['command'] = is_array($argv) && array_is_list($argv) && $argv !== []
                ? array_map('strval', $argv)
                : ['sh', '-c', $provision . self::shellCommand($command)];
        }

        return $service + $this->mountedIdentity();
    }

    /**
     * Same reason as PHP: /app is the customer's directory, so what the
     * application writes there has to belong to them or their SFTP cannot
     * touch it afterwards.
     *
     * @return array<string, string>
     */
    private function mountedIdentity(): array
    {
        $identity = trim((string) ($this->decision['user'] ?? ''));

        return $identity === '' ? [] : ['user' => $identity];
    }

    /**
     * A recipe's start command, ready to hand to `sh -c` inside compose.
     *
     * Two things have to be true and neither is obvious.
     *
     * It runs through a shell because compose's own string form is not one:
     * it would hand `java -jar target/*.jar` an unexpanded glob. `exec` is
     * added so the application keeps PID 1 and its signals -- but only when
     * the command is a single one. A recipe whose start command is a small
     * script (Java's picks the runnable jar out of several before starting
     * it, and ends with its own `exec`) turns into `exec jar="$(ls …)"` and
     * the container restart-loops on `sh: exec: jar=: not found`.
     *
     * And `$` is doubled, because compose interpolates `$var` in a command
     * before the shell ever sees it. Undoubled, that same script's `"$jar"`
     * arrived empty and compose warned about a variable nobody wrote.
     */
    private static function shellCommand(string $command): string
    {
        $compound = preg_match('/(;|&&|\|\||\n)/', $command) === 1
            || str_starts_with(trim($command), 'exec ');

        return str_replace('$', '$$', $compound ? $command : 'exec ' . $command);
    }

    /**
     * PHP runs the shared base image with the account's own directory bind
     * mounted, and builds nothing.
     *
     * The image carries what every PHP application needs and no application's
     * own code -- Apache, the extension set, composer, the entrypoint shim --
     * so it is pulled once per host and shared by every account, instead of
     * ~800MB of near-identical image per project. What makes it *this*
     * project is the mount: `~/project` is the running document root, so a
     * file changed over SFTP is served on the next request and `vendor/`
     * written by the host build step is live without a restart.
     *
     * @return array<string, mixed>
     */
    private function mountedPhp(): array
    {
        $service = [
            'image' => $this->phpImage(),
            'working_dir' => self::WORKDIR,
            'volumes' => [$this->appRoot() . ':' . self::WORKDIR],
            'ports' => ["{$this->port}:{$this->port}"],
        ];
        // The whole container is the hosting account, not root.
        //
        // /app is the customer's own directory, so anything the container
        // writes there -- Laravel's .env and bootstrap/cache, an upload, a
        // config a first-run wizard saves -- has to belong to them or their
        // SFTP cannot touch it afterwards. Compose is the right place to say
        // so: Docker creates the container's stdio owned by this uid too, so
        // Apache can open its logs without ever being root.
        $identity = trim((string) ($this->decision['user'] ?? ''));
        if ($identity !== '') {
            $service['user'] = $identity;
        }
        $cache = trim((string) ($this->decision['composer_cache_dir'] ?? ''));
        if ($cache !== '') {
            $service['volumes'][] = $cache . ':' . PhpBaseImage::COMPOSER_CACHE_DIR;
        }

        return $service;
    }

    /**
     * The subtree to mount, relative to the compose file: the manifest's
     * `app_root`, or the whole checkout when it declares none.
     *
     * Nothing is detected here and nothing is special-cased. A repository
     * whose application is not at its root says so in its recipe, and the
     * generated Dockerfile used to honour that by flattening the subtree with
     * `COPY <app_root>/. .`; mounting the subtree is the same statement made
     * to the mount instead, so the document root, the entrypoint and the host
     * build all still work on /app.
     *
     * Validated rather than trusted: `_schema.json` already rejects an
     * absolute path, but this value ends up as a bind-mount source, so it is
     * worth refusing anything that could climb out of the checkout here too.
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
     * The nginx shape serves files and reads no environment at all; the other
     * two run the application.
     *
     * `env_file` carries the secrets the repository already wrote
     * (BETTER_AUTH_SECRET, …); the generated values below it override the
     * localhost placeholders a template ships with.
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
                PublicUrlEnvironment::for($this->publicUrl),
                ComposeValues::stringMap($this->decision['env'] ?? null)
            ),
        ];
    }
}

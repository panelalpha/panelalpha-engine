<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Strategy\AccountSecrets;
use App\System\Project\Dind\Strategy\BuildContextIgnoreWriter;
use App\System\Project\Dind\Strategy\DockerfileStrategy;
use App\System\Project\Dind\Strategy\EntrypointWriter;
use App\System\Project\Dind\Strategy\FrameworkStrategy;
use App\System\Project\Dind\Strategy\ManifestDatabase;
use App\System\Project\Dind\Strategy\AppConfigBootstrap;
use App\System\Project\Dind\Strategy\PhpStrategy;
use App\System\Project\Dind\Strategy\PrepareStage;
use App\System\Project\Dind\Strategy\RailpackStrategy;
use App\System\Project\Dind\Strategy\RubyStrategy;
use App\System\Project\Dind\Strategy\UserComposeStrategy;
use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\ComposeOverride;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\FrameworkService;
use App\Lib\Deploy\Compose\PublicUrlEnvironment;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Strategies;

/**
 * Turn a detect decision into the files `docker compose up` will build from.
 *
 * This class is the dispatcher: it picks the writer for the strategy that was
 * detected and hands off. Each writer ends by putting a compose file into
 * ~/project — either the user's own, hardened in place, or one the engine
 * generates — plus whatever Dockerfile or nginx config that compose
 * references. Nothing here starts containers; {@see Dind::startUserApp()}
 * does that.
 *
 * The writers live in {@see Strategy} and reach their shared services back
 * through this object, so the accessors below are the whole of what they
 * share:
 *
 *   {@see AppConfigBootstrap}    what the project's app config does before detection
 *   {@see PrepareStage}        host-side work between the clone and the build
 *   {@see EntrypointWriter}    the staged entrypoint a generated image installs
 *   {@see AccountSecrets}      stable per-account secrets nobody supplied
 *   {@see ManifestDatabase}    a manifest's `database:` outside the PHP strategy
 *   {@see UserComposeStrategy} the project's own compose file, hardened
 *   {@see DockerfileStrategy}  a repository that ships its own Dockerfile
 *   {@see RubyStrategy}        Rails and plain Rack applications
 *   {@see PhpStrategy}         Laravel and plain PHP applications
 *   {@see FrameworkStrategy}   a framework a manifest recognised
 *   {@see RailpackStrategy}    build it with Railpack, or serve a page saying why not
 */
class DeployStrategy
{
    private DindProject $dind;

    private ?RuntimeSidecars $runtimeSidecars = null;
    private ?AppConfigBootstrap $bootstrap = null;
    private ?PrepareStage $prepare = null;
    private ?EntrypointWriter $entrypoint = null;
    private ?AccountSecrets $secrets = null;
    private ?ManifestDatabase $database = null;
    /** The app config of the deploy in flight, for {@see composeDecision()}. */
    private ?AppConfig $appConfig = null;
    private ?UserComposeStrategy $userCompose = null;
    private ?DockerfileStrategy $dockerfile = null;
    private ?RubyStrategy $ruby = null;
    private ?PhpStrategy $php = null;
    private ?FrameworkStrategy $framework = null;
    private ?RailpackStrategy $railpack = null;
    private ?BuildContextIgnoreWriter $contextIgnore = null;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    // -------------------------------------------------------------------------
    // Shared services
    // -------------------------------------------------------------------------

    public function sidecars(): RuntimeSidecars
    {
        return $this->runtimeSidecars ??= new RuntimeSidecars($this->dind);
    }

    public function prepare(): PrepareStage
    {
        return $this->prepare ??= new PrepareStage($this->dind);
    }

    public function entrypoint(): EntrypointWriter
    {
        return $this->entrypoint ??= new EntrypointWriter($this->dind);
    }

    public function contextIgnore(): BuildContextIgnoreWriter
    {
        return $this->contextIgnore ??= new BuildContextIgnoreWriter($this->dind);
    }

    public function secrets(): AccountSecrets
    {
        return $this->secrets ??= new AccountSecrets($this->dind);
    }

    /** A manifest's `database:` for the writers outside the PHP strategy. */
    public function database(): ManifestDatabase
    {
        return $this->database ??= new ManifestDatabase($this->dind);
    }

    /**
     * The decision a generated compose file is rendered from.
     *
     * Every strategy that generates one ends here rather than calling the
     * cache and the environment separately, so a strategy added later cannot
     * quietly ship a service the account's own `env_vars` do not reach.
     * {@see ComposeEnvironment} for which keys an account may set and which
     * two groups stay the engine's.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public function composeDecision(array $decision): array
    {
        $decision = ComposeEnvironment::layer(
            ComposeEnvironment::withInstanceSecret($decision, $this->secrets()->instanceSecret()),
            $this->appConfig?->env() ?? [],
            $this->secrets()->userEnvVars()
        );

        return ComposeEnvironment::withPublicAddress($decision, $this->dind->publicAppUrl());
    }

    /**
     * The project's own `.env` and `.env.example`, null when absent: what
     * {@see PublicUrlEnvironment::pathPrefixKeysIn()} reads.
     *
     * @return list<?string>
     */
    public function projectEnvFiles(string $projectDir): array
    {
        return [
            $this->dind->projectTree()->readIn($projectDir, '.env'),
            $this->dind->projectTree()->readIn($projectDir, '.env.example'),
        ];
    }

    /**
     * The decision for a service the engine generates, told which URL keys
     * the project uses as a sub-path ({@see FrameworkService}).
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public function withPathPrefixKeys(array $decision, string $projectDir): array
    {
        $keys = PublicUrlEnvironment::pathPrefixKeysIn($this->projectEnvFiles($projectDir));

        return $keys === [] ? $decision : ['path_prefix_keys' => $keys] + $decision;
    }

    // -------------------------------------------------------------------------
    // The writers
    // -------------------------------------------------------------------------

    private function appConfigBootstrap(): AppConfigBootstrap
    {
        return $this->bootstrap ??= new AppConfigBootstrap($this->dind);
    }

    private function userCompose(): UserComposeStrategy
    {
        return $this->userCompose ??= new UserComposeStrategy($this->dind);
    }

    /**
     * Once `.env` is final: keep the engine's files out of a repository
     * Dockerfile's build context.
     *
     * @param array<string, mixed> $decision
     */
    public function keepEngineFilesOutOfBuildContext(array $decision, string $projectDir, ?string $chown): void
    {
        $strategy = $decision['strategy'] ?? null;
        if ($strategy === Strategies::DOCKERFILE) {
            $this->dockerfile()->keepEngineFilesOutOfContext($decision, $projectDir, $chown);
        } elseif ($strategy === Strategies::COMPOSE || $strategy === Strategies::PAEMD) {
            $this->userCompose()->keepEngineFilesOutOfContext($projectDir, $chown);
        }
    }

    private function dockerfile(): DockerfileStrategy
    {
        return $this->dockerfile ??= new DockerfileStrategy($this->dind);
    }

    public function ruby(): RubyStrategy
    {
        return $this->ruby ??= new RubyStrategy($this->dind);
    }

    private function php(): PhpStrategy
    {
        return $this->php ??= new PhpStrategy($this->dind);
    }

    private function framework(): FrameworkStrategy
    {
        return $this->framework ??= new FrameworkStrategy($this->dind);
    }

    public function railpack(): RailpackStrategy
    {
        return $this->railpack ??= new RailpackStrategy($this->dind);
    }

    // -------------------------------------------------------------------------
    // The pipeline
    // -------------------------------------------------------------------------

    /**
     * Everything the project's app config does to the checkout, before anything
     * is detected. See {@see AppConfigBootstrap}.
     */
    public function bootstrap(?AppConfig $appConfig, string $projectDir, ?string $chown): void
    {
        $this->userCompose()->noteRepositoryOverride($projectDir);
        $this->appConfigBootstrap()->run($appConfig, $projectDir, $chown);
    }

    /**
     * @param array{
     *   strategy: string,
     *   label: string,
     *   compose_path: ?string,
     *   dockerfile: ?string,
     *   port_hint: ?int,
     *   runtime: ?string,
     * } $decision
     */
    public function apply(
        array $decision,
        ?AppConfig $appConfig,
        string $projectDir,
        ?string $chown,
        string $sourceLabel
    ): void {
        $strategy = $decision['strategy'];
        // Held for composeDecision(): not every writer below is handed the
        // app config, and every generated compose file needs what it declared
        // about the environment.
        $this->appConfig = $appConfig;

        if ($strategy === Strategies::COMPOSE) {
            $this->userCompose()->apply($decision, $appConfig, $projectDir, $chown, $sourceLabel);

            return;
        }

        // Every other strategy generates its own build definition below, so
        // the platform's prepare runs first — it may write the very files the
        // generator is about to read.
        $this->prepare()->run($projectDir, $this->prepare()->manifestFor($decision, $appConfig), $appConfig);

        if ($strategy === Strategies::DOCKERFILE) {
            $this->dockerfile()->apply($decision, $projectDir, $chown);

            return;
        }

        if ($strategy === Strategies::RAILS || $strategy === Strategies::RUBY) {
            $this->ruby()->apply($decision, $projectDir, $chown, $appConfig);

            return;
        }

        if ($strategy === Strategies::LARAVEL || $strategy === Strategies::PHP) {
            $this->php()->apply($projectDir, $chown, $strategy === Strategies::LARAVEL, $appConfig, $decision);

            return;
        }

        if (Strategies::isGenerated($strategy)) {
            $this->framework()->apply($decision, $projectDir, $chown, $appConfig);

            return;
        }

        if ($strategy === Strategies::RAILPACK) {
            $this->railpack()->applyRailpackOrFallback($projectDir, $chown, $sourceLabel);

            return;
        }

        if ($strategy === Strategies::STATIC) {
            // The whole recipe for a site with no build step: which document
            // answers `/`. Detection worked it out -- static.yaml read it off
            // a filename, html.yaml went looking -- and until now nothing
            // downstream read the answer, so a site whose front page was
            // called anything but index.html was mounted correctly and then
            // served a 403.
            $this->dind->composeWriter()->writeStaticNginxConf(
                $projectDir,
                is_string($decision['static_index'] ?? null) ? $decision['static_index'] : null,
                $chown
            );
            $this->dind->composeWriter()->writeGeneratedCompose(
                $projectDir,
                DeployCompose::staticNginx(),
                $chown
            );

            return;
        }

        $this->railpack()->applyFallback($projectDir, $chown, $sourceLabel);
    }

    /**
     * An app config's override may name a service the run file no longer has,
     * with nothing to build it from; compose would refuse the whole project.
     */
    public function dropUndefinedOverrideServices(?string $chown): void
    {
        $override = $this->dind->userAppComposeOverridePath();
        $fs = $this->dind->system()->filesystem();
        if (!$fs->fileExists($override)) {
            return;
        }
        $defined = [];
        foreach ($this->dind->userAppComposeFiles() as $file) {
            if ($file === $override || !$fs->fileExists($file)) {
                continue;
            }
            $services = ComposeYaml::parse($fs->fileGetContents($file))['services'] ?? null;
            foreach (is_array($services) ? array_keys($services) : [] as $name) {
                $defined[] = (string) $name;
            }
        }
        $trimmed = ComposeOverride::withoutUndefinedServices($fs->fileGetContents($override), $defined);
        if ($trimmed['dropped'] === [] || $trimmed['yaml'] === null) {
            return;
        }
        foreach ($trimmed['dropped'] as $name) {
            $this->dind->shell()->logger()?->warn(
                "Not layering service {$name} from the app config's compose override: the application has no such service, and the override gives it no image or build"
            );
        }
        $fs->filePutContents($override, $trimmed['yaml'], $chown, '644');
    }

    /**
     * Rails 6+ Host Authorization blocks the configured public vhost when the
     * app keeps a host whitelist. Drop a tiny initializer that adds it.
     */
    public function installRailsHostInitializer(string $projectDir, ?string $chown): void
    {
        $this->ruby()->installHostInitializer($projectDir, $chown);
    }

    /**
     * Re-normalize the project's own compose file into the run file, without
     * running prepare or touching anything else. For {@see ContainerOperations}
     * to call before `up`/`pull` on a compose-strategy project, so an edit the
     * client made takes effect (ticket 05).
     */
    public function refreshComposeRunFile(string $projectDir, ?string $chown): void
    {
        $this->userCompose()->refreshRunFile($projectDir, $chown);
    }
}

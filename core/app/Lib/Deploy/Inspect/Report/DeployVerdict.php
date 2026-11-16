<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\Platform\PlatformCandidates;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Findings\Finding;
use App\System\Project\Dind\Strategy\RailpackStrategy;

/**
 * What a deploy of the inspected directory would serve: the application, the "Project not configured" placeholder,
 * or nothing at all. Only the first is deployable; `no_start_command` deploys, with a warning.
 */
final class DeployVerdict
{
    public const DEPLOYABLE = 'deployable';

    public const NOT_DEPLOYABLE = 'not_deployable';

    public const PLACEHOLDER = 'placeholder';

    public const NO_START_COMMAND = 'no_start_command';

    public const CODE_UNRECOGNISED = 'repository_unrecognised';

    public const CODE_NOTHING_TO_START = 'node_nothing_to_start';

    public const CODE_NO_START_SCRIPT = 'node_start_script_missing';

    public const UNRECOGNISED = 'No recipe recognises it, so the deploy would serve the "Project not configured" '
        .'placeholder page instead of an application.';

    public const RAILPACK_MAY_BUILD = ' A toolchain the engine knows reads it, so the recipe `railpack` may build it.';

    public const DOTNET_SERVES_NOTHING = ' Its .NET projects are desktop apps, libraries, tests or console programs; '
        .'none serves HTTP, so no strategy can run it, `railpack` included.';

    public const DOTNET_FRAMEWORK_UNRESOLVED = ' Its .NET projects that could serve HTTP, such as %s, name their target '
        .'framework only through an MSBuild property (`%s`) the repository\'s own build sets, so they are passed over: '
        .'no SDK can be chosen for them. The rest are desktop apps, libraries, tests or console programs, so no strategy '
        .'can run it, `railpack` included.';

    public const NOTHING_TO_START = 'package.json has no start script, no main and no index.js or index.ts, and '
        .'nothing builds pages to serve instead, so the deploy would serve the "Project not configured" placeholder page.';

    public const NO_START_SCRIPT = 'package.json has no start script, so nothing says how to run it as a server; '
        .'a package without one is usually a library, which builds but serves nothing.';

    /** Root files Railpack starts, or builds with a provider it tries before Node (no engine runtime reads those). */
    private const RAILPACK_STARTABLE = ['index.js', 'index.ts', 'nx.json', 'mix.exs', 'deno.json', 'deno.jsonc'];

    private function __construct(public readonly string $verdict, public readonly ?Finding $issue = null) {}

    /**
     * @param  array<string, mixed>  $decision
     * @param  ?Finding  $issue  why detection or the deployability check refused it
     */
    public static function of(array $decision, ProjectContext $context, ?Finding $issue): self
    {
        $strategy = (string) ($decision['strategy'] ?? Strategies::FALLBACK);

        return match (true) {
            $issue !== null => new self(self::NOT_DEPLOYABLE, $issue),
            $strategy === Strategies::FALLBACK => new self(self::PLACEHOLDER, new Finding(self::CODE_UNRECOGNISED, self::unrecognised($context))),
            $strategy !== Strategies::RAILPACK || ! self::packageWithoutStartScript($context) => new self(self::DEPLOYABLE),
            self::railpackStartsNothing($context) => new self(self::PLACEHOLDER, new Finding(self::CODE_NOTHING_TO_START, self::NOTHING_TO_START)),
            default => new self(self::NO_START_COMMAND),
        };
    }

    public function deployable(): bool
    {
        return $this->issue === null;
    }

    private static function unrecognised(ProjectContext $context): string
    {
        return match (true) {
            PlatformCandidates::onlyDotnetServingNothing($context) => self::UNRECOGNISED.self::dotnetServingNothing($context),
            RuntimeRegistry::anyRecognises($context) => self::UNRECOGNISED.self::RAILPACK_MAY_BUILD,
            default => self::UNRECOGNISED,
        };
    }

    /** dotnet/maui passes over its sample servers for their framework, which is not "none serves HTTP". */
    private static function dotnetServingNothing(ProjectContext $context): string
    {
        $unresolved = DotnetRuntime::unresolvedFrameworkProject($context->projectDir);

        return $unresolved === null
            ? self::DOTNET_SERVES_NOTHING
            : sprintf(self::DOTNET_FRAMEWORK_UNRESOLVED, $unresolved['file'], $unresolved['framework']);
    }

    private static function packageWithoutStartScript(ProjectContext $context): bool
    {
        return $context->package() !== null && $context->script('start') === '';
    }

    /**
     * Railpack's Node provider starts the start script, else `main`, else a root index.js or index.ts; an image that
     * starts nothing is served from a built or committed dist/, public/ or build/ instead, and otherwise not at all.
     */
    private static function railpackStartsNothing(ProjectContext $context): bool
    {
        $main = $context->package()['main'] ?? null;

        return RuntimeRegistry::recognisedBy($context) === ['node']
            && (! is_string($main) || trim($main) === '')
            && ! self::hasAnyRootFile($context, self::RAILPACK_STARTABLE)
            && $context->script('build') === ''
            && ! self::shipsStaticOutput($context);
    }

    /**
     * @param  list<string>  $names
     */
    private static function hasAnyRootFile(ProjectContext $context, array $names): bool
    {
        foreach ($names as $name) {
            if ($context->hasFile($name)) {
                return true;
            }
        }

        return false;
    }

    private static function shipsStaticOutput(ProjectContext $context): bool
    {
        foreach (RailpackStrategy::STATIC_OUTPUT_DIRS as $dir) {
            if ($context->isFile($dir.'/index.html')) {
                return true;
            }
        }

        return false;
    }
}

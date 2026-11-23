<?php

namespace App\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformCandidates;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\System\Project\Dind;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\HostDiskLowException;
use Illuminate\Support\Facades\Log;

/**
 * A deploy that failed for a reason of the project's own tries again, until one attempt starts or none is left:
 * first the recipe with every other JS package manager the project has a lockfile for, then the next recipe that
 * claims the project, by descending priority. A requested recipe keeps its recipe and varies only the package
 * manager; a source recipe or the project's app config names everything. What started is remembered, and the
 * next deploy starts with it.
 */
final class RecipeFallback
{
    /** The recipe a fallback started, which the next deploy tries first. */
    public const DETAIL_RECIPE = 'deploy_fallback_recipe';

    /** The package manager a fallback started with, which the next deploy installs with first. */
    public const DETAIL_PACKAGE_MANAGER = 'deploy_fallback_package_manager';

    /** The package manager the last prepare installed with; null for a recipe that uses none. */
    public const DETAIL_DEPLOYED_PACKAGE_MANAGER = 'deploy_package_manager';

    /** What the last deploy tried, for the deploy report; null when it tried only what detection picked. */
    public const DETAIL_REPORT = 'deploy_fallback';

    /** Failures of the host, the network or the deploy's own limits, which another attempt would only repeat. */
    private const INFRASTRUCTURE_RULES = [
        'host-namespaces-unavailable',
        'disk-limit-reached',
        'disk-full',
        'out-of-memory',
        'build-stalled',
        'build-timed-out',
        'step-timed-out',
        'clone-timed-out',
        'submodule-fetch-failed',
        'sysbox-fs-connection-lost',
        'sysbox-not-running',
        'registry-rate-limited',
        'build-dns-unavailable',
        'build-daemon-fault',
        'layer-digest-mismatch',
        'native-build-headers-download-failed',
        'repo-read-interrupted',
        'repo-auth-failed',
        'repo-not-found',
    ];

    /** @var list<string>|null */
    private ?array $candidates = null;

    /** @var list<string>|null */
    private ?array $packageManagers = null;

    public function __construct(private readonly Dind $dind) {}

    /**
     * The first attempt's failure is the one reported when none starts, and the project is prepared for it again,
     * so what is recorded and what is on disk agree with it.
     *
     * @param  \Closure(): array{stdout: string, stderr: string, exit_code: ?int}  $start
     * @param  \Closure(): void  $prepare  writes the deploy files for what the choice context names
     * @param  ?RecipeAttempt  $failedPrepare  the first attempt's prepare, when it failed and {@see defers()} it
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     *
     * @throws \Exception the first attempt's, when it threw
     */
    public function start(\Closure $start, \Closure $prepare, ?RecipeAttempt $failedPrepare = null): array
    {
        $asFirst = ['recipe' => $this->choice()->fallback(), 'manager' => $this->choice()->packageManager()];
        $first = $failedPrepare ?? RecipeAttempt::run($start);
        $tried = [$this->deployed(null)];
        $outcomes = [$first];
        $last = $first;
        while ($last->failed() && $this->fallsBack($last) && ($next = $this->nextAttempt($tried)) !== null) {
            $this->logger()?->warn(sprintf(
                'Recipe %s failed (%s); trying %s',
                self::describe($tried[array_key_last($tried)]),
                $last->reason(),
                self::describe($next)
            ));
            $this->logger()?->throwIfCancelled();
            $this->clear($last);
            $this->choose($next);
            $last = RecipeAttempt::run(static function () use ($prepare, $start): array {
                $prepare();

                return $start();
            });
            $tried[] = $this->deployed($next);
            $outcomes[] = $last;
        }

        $started = ! $last->failed();
        if (! $started && count($tried) > 1) {
            $this->returnToFirst($tried, $asFirst, $last, $prepare);
        }
        $this->settle($started, $tried, $outcomes, $asFirst);

        return $started ? $last->result() : $first->reported($last);
    }

    /**
     * Whether a failed prepare of the first attempt is left for start() to fall back from: a refusal of the source is
     * the deploy's, and with nothing else to try there is nothing to wait for.
     */
    public function defers(RecipeAttempt $prepare): bool
    {
        return ! $prepare->threw(\InvalidArgumentException::class)
            && $this->fallsBack($prepare)
            && $this->nextAttempt([$this->deployed(null)]) !== null;
    }

    private function fallsBack(RecipeAttempt $attempt): bool
    {
        if ($attempt->threw(HostDiskLowException::class)) {
            return false;
        }
        $rule = DeployFailureExplainer::match($attempt->output())['rule'] ?? null;

        return ! in_array($rule, self::INFRASTRUCTURE_RULES, true);
    }

    /**
     * Another package manager for the last recipe, when it installed with one, else the next recipe with the one
     * detection picks for it.
     *
     * @param  non-empty-list<array{recipe: ?string, manager: ?string}>  $tried
     * @return ?array{recipe: ?string, manager: ?string}
     */
    private function nextAttempt(array $tried): ?array
    {
        $requested = $this->choice()->requested();
        if ($requested === null && $this->candidates() === []) {
            return null;
        }
        $last = $tried[array_key_last($tried)];
        if ($last['manager'] !== null) {
            foreach ($this->packageManagers() as $manager) {
                $attempt = ['recipe' => $last['recipe'], 'manager' => $manager];
                if (! in_array($attempt, $tried, true)) {
                    return $attempt;
                }
            }
        }
        if ($requested !== null) {
            return null;
        }
        $triedRecipes = array_column($tried, 'recipe');
        foreach ($this->candidates() as $id) {
            if (! in_array($id, $triedRecipes, true)) {
                return ['recipe' => $id, 'manager' => null];
            }
        }

        return null;
    }

    /**
     * Read once, before a second attempt has compiled anything into the checkout.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        try {
            return $this->candidates ??= PlatformCandidates::fallbackOrder($this->context());
        } catch (ManifestException) {
            return $this->candidates = [];
        }
    }

    /** @return list<string> */
    private function packageManagers(): array
    {
        return $this->packageManagers ??= JsPackageManager::lockfileManagers($this->context()->files);
    }

    private function context(): ProjectContext
    {
        return ProjectContext::at($this->dind->userAppDirPath(), $this->dind->userModel()->getGitRepo());
    }

    /**
     * What the last prepare froze, or what was asked for when it failed before freezing anything.
     *
     * @param  ?array{recipe: ?string, manager: ?string}  $asked
     * @return array{recipe: ?string, manager: ?string}
     */
    private function deployed(?array $asked): array
    {
        $details = $this->dind->userModel()->getDetails();
        $platform = $details['deploy_platform'] ?? null;
        $manager = $details[self::DETAIL_DEPLOYED_PACKAGE_MANAGER] ?? null;
        $recipe = is_string($platform) && $platform !== '' ? $platform : null;
        if ($asked !== null && $asked['recipe'] !== $recipe) {
            return $asked;
        }

        return ['recipe' => $recipe, 'manager' => is_string($manager) ? $manager : null];
    }

    /** @param  array{recipe: ?string, manager: ?string}  $attempt */
    private function choose(array $attempt): void
    {
        if ($attempt['recipe'] !== null && $this->choice()->requested() === null) {
            $this->choice()->fallTo($attempt['recipe']);
        }
        $this->choice()->fallToPackageManager($attempt['manager']);
    }

    /** @param  array{recipe: ?string, manager: ?string}  $attempt */
    private static function describe(array $attempt): string
    {
        $recipe = $attempt['recipe'] ?? 'detected';

        return $attempt['manager'] === null ? $recipe : "{$recipe} with {$attempt['manager']}";
    }

    /** A release that failed or a version kept serving started nothing to take down; anything else half-started did. */
    private function clear(RecipeAttempt $attempt): void
    {
        $result = $attempt->resultOrNull();
        if ($result !== null && (! empty($result[AppLauncher::RELEASE_FAILED]) || ZeroDowntimeRedeploy::keptServing($result) !== null)) {
            return;
        }
        try {
            $this->dind->project()->abortRunningDeploy(stopInnerDocker: false);
        } catch (\Exception $e) {
            Log::warning("Failed recipe cleanup for {$this->dind->username()}: {$e->getMessage()}");
        }
    }

    /**
     * @param  non-empty-list<array{recipe: ?string, manager: ?string}>  $tried
     * @param  array{recipe: ?string, manager: ?string}  $asFirst  the choice the first attempt was prepared with
     */
    private function returnToFirst(array $tried, array $asFirst, RecipeAttempt $last, \Closure $prepare): void
    {
        $this->logger()?->warn(sprintf(
            'Nothing that claims the project started (tried %s; the last failed with %s); reporting the failure of %s',
            implode(', ', array_map(self::describe(...), $tried)),
            $last->reason(),
            self::describe($tried[0])
        ));
        $this->clear($last);
        $this->choice()->fallTo($asFirst['recipe']);
        $this->choice()->fallToPackageManager($asFirst['manager']);
        try {
            $prepare();
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Could not prepare '.$this->dind->username().' for '.self::describe($tried[0]).' again: '.$e->getMessage());
        }
    }

    /**
     * What started is remembered, unless detection would choose it anyway; nothing is when nothing started. The
     * attempts are kept for the deploy report ({@see report()}).
     *
     * @param  non-empty-list<array{recipe: ?string, manager: ?string}>  $tried
     * @param  non-empty-list<RecipeAttempt>  $outcomes
     * @param  array{recipe: ?string, manager: ?string}  $asFirst
     */
    private function settle(bool $started, array $tried, array $outcomes, array $asFirst): void
    {
        $recipe = $started ? $this->choice()->fallback() : null;
        if ($recipe !== null && $recipe === ($this->candidates()[0] ?? null)) {
            $recipe = null;
        }
        $this->save([
            self::DETAIL_RECIPE => $recipe,
            self::DETAIL_PACKAGE_MANAGER => $started ? $this->choice()->packageManager() : null,
            self::DETAIL_REPORT => self::report($started, $tried, $outcomes, $asFirst),
        ]);
    }

    /**
     * Every attempt with the rule it failed with, and what started; null for a deploy that tried only what detection
     * picked, which the report's strategy already says.
     *
     * @param  non-empty-list<array{recipe: ?string, manager: ?string}>  $tried
     * @param  non-empty-list<RecipeAttempt>  $outcomes
     * @param  array{recipe: ?string, manager: ?string}  $asFirst
     * @return ?array{remembered: bool, attempts: list<array{recipe: ?string, package_manager: ?string, failed: bool, rule: ?string}>, started: ?array{recipe: ?string, package_manager: ?string}}
     */
    private static function report(bool $started, array $tried, array $outcomes, array $asFirst): ?array
    {
        $remembered = $asFirst['recipe'] !== null || $asFirst['manager'] !== null;
        if (count($tried) === 1 && ! $remembered) {
            return null;
        }
        $attempts = [];
        foreach ($tried as $i => $attempt) {
            $attempts[] = [
                'recipe' => $attempt['recipe'],
                'package_manager' => $attempt['manager'],
                'failed' => $outcomes[$i]->failed(),
                'rule' => $outcomes[$i]->rule(),
            ];
        }
        $last = $tried[array_key_last($tried)];

        return [
            'remembered' => $remembered,
            'attempts' => $attempts,
            'started' => $started ? ['recipe' => $last['recipe'], 'package_manager' => $last['manager']] : null,
        ];
    }

    /** @param  array<string, mixed>  $values */
    private function save(array $values): void
    {
        $user = $this->dind->userModel();
        $details = $user->getDetails();
        foreach ($values as $key => $value) {
            if (($details[$key] ?? null) !== $value) {
                $user->setDetails($values);
                $user->save();

                return;
            }
        }
    }

    private function choice(): RecipeChoiceContext
    {
        return app(RecipeChoiceContext::class);
    }

    private function logger(): ?DeployLogger
    {
        return $this->dind->shell()->logger();
    }
}

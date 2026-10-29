<?php

namespace App\Lib\Project;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Source\GitUrl;
use App\Models\Task;
use App\Models\User;

/**
 * Giving a project created before its source was known the git source it
 * deploys from.
 *
 * The onboarding domain step creates a dind account with no source, so its
 * deploy stops waiting for files. This reads the repository as a create does,
 * stores it, and runs the source rebuild a redeploy runs.
 */
final class SourceAttacher
{
    /** The deploy statuses that mean an application was already produced. */
    private const DEPLOYED_STATUSES = ['success', 'partial'];

    /** What the last attach's inspection found, for the response. */
    private ?CreateInspection $inspection = null;

    public function __construct(private readonly ProjectRebuild $rebuild) {}

    /**
     * @throws ProblemException source_already_attached, project_not_eligible
     */
    public function attach(User $user, NewProjectInput $input, ?DeployPlan $plan, ?string $recipe): Task
    {
        $this->assertEligible($user);
        $params = ProjectCreator::resolvedSource($input->params + ['username' => $user->username], $input);
        $this->inspection = $this->inspect($params, $plan, $recipe);
        $this->storeSource($user, $params, $input->envVars);

        return $this->rebuild->queue(
            $user,
            ProjectRebuild::ATTACH_SOURCE,
            null,
            $plan,
            $recipe,
            ['git_repo' => GitUrl::sanitize((string) $params['git_repo'])],
        );
    }

    /** What the repository inspection found, for the 202 response. */
    public function inspection(): ?CreateInspection
    {
        return $this->inspection;
    }

    /**
     * Seconds, and never a refusal: what it finds is reported in the response
     * and the attach goes ahead.
     *
     * @param  array<string, mixed>  $params
     */
    private function inspect(array $params, ?DeployPlan $plan, ?string $recipe): CreateInspection
    {
        return app(CreateInspector::class)->inspect(
            (string) $params['git_repo'],
            $params['git_branch'] ?? null,
            $params['git_token'] ?? null,
            $recipe,
            $plan,
        );
    }

    /**
     * Only a dind project that has not produced an application can take a
     * source; an account already deploying something is not replaced by it.
     *
     * @throws ProblemException
     */
    private function assertEligible(User $user): void
    {
        if ($user->hasGitProject()) {
            throw ProblemException::conflict(
                'git_repo',
                'source_already_attached',
                "Project '{$user->username}' already deploys from ".GitUrl::sanitize($user->getGitRepoOrFail()).'.',
            );
        }

        if ($user->getTemplate() !== 'dind') {
            throw ProblemException::conflict(
                'git_repo',
                'project_not_eligible',
                "Project '{$user->username}' is classic shared hosting, so a git source cannot be attached to it. "
                    .'Create the project with `template: dind` to deploy it from git.',
            );
        }

        if (self::producedAnApplication($user)) {
            throw ProblemException::conflict(
                'git_repo',
                'project_not_eligible',
                "Project '{$user->username}' already runs an application, so a git source cannot replace it.",
            );
        }
    }

    private static function producedAnApplication(User $user): bool
    {
        return in_array($user->getDeploymentStatus(), self::DEPLOYED_STATUSES, true)
            || $user->hasDeployedBefore();
    }

    /**
     * The source the deploy will read: the token encrypted by the model, and
     * the env overrides merged onto the ones the project already carries.
     *
     * @param  array<string, mixed>  $params
     * @param  mixed  $sentEnvVars  the env_vars as sent, before their vault references were read
     */
    private function storeSource(User $user, array $params, mixed $sentEnvVars): void
    {
        $user->setDetails([
            'git_repo' => $params['git_repo'],
            'git_branch' => $params['git_branch'] ?? null,
            'git_token' => $params['git_token'] ?? null,
        ]);
        if (array_key_exists('env_vars', $params)) {
            $user->setEnvVars(EnvVarOverrides::applyIncoming($params['env_vars'], $user->getEnvVars()), $sentEnvVars);
        }

        $user->save();
    }
}

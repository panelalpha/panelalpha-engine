<?php

namespace App\Lib\Project\Create;

use App\Exceptions\ProblemException;
use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Project\SourceAttacher;
use App\Lib\Upload\ArchiveSource;
use App\Lib\Upload\ArchiveSources;
use App\Lib\Upload\Uploads;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * PUT /projects/{username}/source: a git source for a container project that has none, or an archive replacing
 * ~/project, deployed while no other deploy runs. POST /projects/{username}/source runs the same git attach.
 */
class ProjectSourceSetter
{
    public function __construct(
        private readonly SourceAttacher $attacher,
        private readonly ProjectRebuild $rebuild,
        private readonly Uploads $uploads,
    ) {}

    /**
     * @throws ValidationException
     */
    public function set(User $user, SourceSetInput $set): QueuedDeploy
    {
        return CreateFields::answering(fn (): QueuedDeploy => $set->source->type === CreateShape::SOURCE_GIT
            ? $this->attachGit($user, $set->input)
            : $this->deployArchive($user, $set));
    }

    /**
     * A git source for a project created without one, refusals named by the flat fields.
     *
     * @throws ValidationException
     */
    public function attachGit(User $user, NewProjectInput $input): QueuedDeploy
    {
        $plan = DeployPlanInput::parse($input->stages);
        $recipe = RecipeChoiceInput::parse($input->recipe);

        return $this->rebuild->whileIdle($user, fn (): QueuedDeploy => new QueuedDeploy(
            $this->attacher->attach($user, $input, $plan, $recipe),
            $this->attacher->inspection(),
        ));
    }

    private function deployArchive(User $user, SourceSetInput $set): QueuedDeploy
    {
        $plan = DeployPlanInput::parse($set->input->stages);
        $recipe = RecipeChoiceInput::parse($set->input->recipe);
        self::assertTakesAnArchive($user);
        ArchiveSources::assertReachable($set->source->archiveUrl());

        return $this->rebuild->whileIdle($user, function () use ($user, $set, $plan, $recipe): QueuedDeploy {
            $this->storeEnvVars($user, $set);

            return new QueuedDeploy($this->queueArchive($user, $set->source, $plan, $recipe));
        });
    }

    private function queueArchive(User $user, SourceSpec $source, ?DeployPlan $plan, ?string $recipe): Task
    {
        $path = $source->archivePath();
        if ($path !== null) {
            $this->rebuild->assertArchive($user, $path);
        }
        $archive = $this->archiveFor($user, $source);
        $this->recordSource($user, $archive);

        return $this->rebuild->queue(
            $user,
            ProjectRebuild::DEPLOY_ARCHIVE,
            $path,
            $plan,
            $recipe,
            details: ['source_type' => CreateShape::SOURCE_ARCHIVE, ...$archive->taskDetails()],
            archive: $archive->isDropped() ? $archive : null,
        );
    }

    /** An upload is claimed for this project here, and moved into it by the deploy. */
    private function archiveFor(User $user, SourceSpec $source): ArchiveSource
    {
        $uploadId = $source->uploadId();
        if ($uploadId !== null) {
            $this->uploads->claimUsable($uploadId, 'source.upload_id', $user->username);

            return ArchiveSource::upload($uploadId);
        }

        $url = $source->archiveUrl();

        return $url !== null ? ArchiveSource::url($url) : ArchiveSource::path((string) $source->archivePath());
    }

    private function recordSource(User $user, ArchiveSource $archive): void
    {
        $user->setDetails(['source' => $archive->recorded()]);
        $user->save();
    }

    private function storeEnvVars(User $user, SourceSetInput $set): void
    {
        if (! $set->setsEnvVars) {
            return;
        }

        $sent = $set->input->envVars;
        $this->rebuild->storeEnvVars($user, ProjectRebuild::sentEnvVars($user, $sent), $sent);
    }

    /** @throws ProblemException 409 */
    private static function assertTakesAnArchive(User $user): void
    {
        if ($user->getTemplate() !== 'dind') {
            throw ProblemException::conflict('source.type', 'project_not_eligible',
                "Project '{$user->username}' is php_hosting; an archive deploys only into a container project.");
        }
        if ($user->hasGitProject()) {
            throw ProblemException::conflict('source.type', 'archive_on_git_project',
                "Project '{$user->username}' deploys from ".GitUrl::sanitize((string) $user->getGitRepo())
                .'; an archive cannot replace it. Push the change to the repository and rebuild instead.');
        }
    }
}

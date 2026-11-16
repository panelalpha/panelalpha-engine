<?php

namespace App\Lib\Project\Create;

use App\Exceptions\ProblemException;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCopier;
use App\Lib\Project\ProjectCreator;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * POST /projects, queued: a flat body goes to {@see ProjectCreator}; a typed copy goes through {@see ProjectCopier},
 * any other typed body through ProjectCreator plus its source's follow-up.
 */
class ProjectCreation
{
    public function __construct(
        private readonly ProjectCreator $creator,
        private readonly ProjectCopier $copier,
    ) {}

    /**
     * @throws ValidationException
     */
    public function queue(ProjectSpec $spec): QueuedDeploy
    {
        $source = $spec->source;
        if ($source === null) {
            return new QueuedDeploy($this->creator->queue($spec->input), $this->creator->inspection());
        }

        return CreateFields::answering(fn (): QueuedDeploy => $source->type === CreateShape::SOURCE_PROJECT
            ? new QueuedDeploy($this->queueCopy($spec->input, $source))
            : $this->queueCreate($spec->input, $source));
    }

    private function queueCreate(NewProjectInput $input, SourceSpec $source): QueuedDeploy
    {
        $followUp = CreateFollowUp::for($source);
        try {
            $task = $this->creator->queue($input, $followUp);
        } catch (Throwable $e) {
            $followUp->release();
            throw $e;
        }

        return new QueuedDeploy($task, $this->creator->inspection());
    }

    private function queueCopy(NewProjectInput $input, SourceSpec $source): Task
    {
        $from = (string) $source->copiedFrom();
        $original = User::findByUsername($from)
            ?? throw ProblemException::one('source.from', 'source_project_not_found', "No project named '{$from}' to copy.");

        return $this->copier->queue($original, $input->params['username'] ?? null, $input->params['domain'] ?? null, $source->copyDatabases());
    }
}

<?php

namespace App\Lib\Project;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Jobs\CopyProject;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Domains\CopyDomain;
use App\Lib\Helper;
use App\Lib\Host\ProjectMemory;
use App\Lib\Project\Create\CreateShape;
use App\Lib\Task\TaskTrigger;
use App\Models\Task;
use App\Models\User;
use App\System;
use App\System\Projects;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A project copied from another under its own name and domain, in the request or queued ({@see CopyProject}): template,
 * limits, settings, home and, unless asked not to, databases; never subdomains, FTP/SFTP accounts or dedicated IPs.
 */
class ProjectCopier
{
    /**
     * Prepared and copied in this process, as POST /projects/{name}/clone does it.
     *
     * @return array{0: User, 1: list<string>} the copy, and what it could not carry over
     *
     * @throws ValidationException|ProblemException
     */
    public function cloneNow(User $source, ?string $name, ?string $domain, bool $copyDatabases): array
    {
        return $this->copy($source, $this->prepare($source, $name, $domain), $copyDatabases);
    }

    /**
     * A create with a `project` source: the copy's name and domain are taken
     * before queueing, so the 202 can say them; the copying itself is the task's.
     *
     * @throws ValidationException|ProblemException
     */
    public function queue(User $source, ?string $name, ?string $domain, bool $copyDatabases): Task
    {
        $copy = $this->prepare($source, $name, $domain);
        $copy->setDetails(['source' => ['type' => CreateShape::SOURCE_PROJECT, 'from' => $source->username]]);
        $copy->save();

        $task = Task::start(
            jobType: CopyProject::class,
            queue: 'default',
            username: $copy->username,
            details: [
                'username' => $copy->username,
                'domain' => $copy->domain,
                'action' => 'copy',
                'trigger' => TaskTrigger::current(),
                'source_type' => CreateShape::SOURCE_PROJECT,
                'from' => $source->username,
                'copy_databases' => $copyDatabases,
            ],
        );
        CopyProject::dispatch((int) $source->getKey(), (int) $copy->getKey(), $copyDatabases)->attachTask($task);

        return $task;
    }

    /**
     * The copy a CopyProject task runs into the rows its create prepared, both
     * found by id; a failure is thrown with its reason, the copy already destroyed.
     *
     * @param  callable(string): void  $log  a line for the task log
     *
     * @throws NotFoundException|RuntimeException
     */
    public function copyInto(int $copyId, int $sourceId, bool $copyDatabases, callable $log): void
    {
        $copy = User::query()->whereKey($copyId)->first()
            ?? throw new NotFoundException('The copy no longer exists.', User::class);
        $source = User::query()->whereKey($sourceId)->first()
            ?? throw new NotFoundException("The project '{$copy->username}' was being copied from no longer exists.", User::class);

        $log("Copying project {$source->username} into {$copy->username}");
        try {
            [, $warnings] = $this->copy($source, $copy, $copyDatabases);
        } catch (ValidationException $e) {
            throw new RuntimeException(implode(' | ', array_merge(...array_values($e->errors()))), 0, $e);
        }
        array_map($log, $warnings);
        $log('Copy finished');
    }

    /** A copy that will not be made: the rows its create saved are removed, best effort. */
    public function discard(int $copyId): void
    {
        $copy = User::query()->whereKey($copyId)->first();
        if ($copy === null) {
            return;
        }

        try {
            $copy->project()->destroy();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The copy's account rows, saved: name and domain checked free, the
     * original's memory limit checked against this host. Nothing is copied yet.
     *
     * @throws ValidationException naming `new_username` or `domain`
     */
    public function prepare(User $source, ?string $name, ?string $domain): User
    {
        $system = new System;
        $newUsername = $this->copyName($source, $name, $system);
        $copyDomain = $this->copyDomain($source, $domain, $newUsername);
        ProjectMemory::assertFits(ProjectMemory::resolve($source->getMemoryLimit()), Projects::isDind($source));
        Projects::assertDatabaseNamesFit($source, $newUsername.'_');
        $this->assertHostingFree($source, $newUsername);

        return $source->makeClone($newUsername, $copyDomain);
    }

    /**
     * Copy $source into the prepared $copy; a copy that fails is destroyed.
     *
     * @return array{0: User, 1: list<string>} the copy, and what it could not carry over
     *
     * @throws ProblemException `clone_failed`, or the rule the failure matched
     */
    public function copy(User $source, User $copy, bool $copyDatabases): array
    {
        try {
            $warnings = (new System)->projects()->clone($source, $copy, $copyDatabases)->warnings ?? [];

            return [$copy->refresh(), $warnings];
        } catch (\Throwable $e) {
            $this->failed($copy, $e);
        }
    }

    /** @throws ValidationException when no free name can be generated, or the name is taken */
    private function copyName(User $source, ?string $requested, System $system): string
    {
        $newUsername = ! empty($requested) ? $requested : Helper::generateCloneUsername($source->username);
        if ($newUsername === null) {
            throw ValidationException::withMessages([
                'new_username' => 'Could not generate an available username for the clone.',
            ]);
        }

        $refusal = ProjectNameRefusal::messageFor($newUsername, $system);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['new_username' => $refusal]);
        }

        return $newUsername;
    }

    /**
     * The requested domain without `www.`, or a planned one ({@see CopyDomain}).
     *
     * @throws ValidationException when none can be generated, or the copy may not take the domain
     */
    private function copyDomain(User $source, ?string $requested, string $newUsername): CopyDomain
    {
        $copyDomain = CopyDomain::forCopy($source->domain, $requested);
        if ($copyDomain === null) {
            throw ValidationException::withMessages([
                'domain' => 'Could not generate an available staging domain for the clone.',
            ]);
        }

        $refusal = $copyDomain->refusal($newUsername);
        if ($refusal !== null) {
            throw ProblemException::one('domain', $refusal['code'], $refusal['message']);
        }

        return $copyDomain;
    }

    /** @throws ValidationException when an account by that name is already on the host */
    private function assertHostingFree(User $source, string $newUsername): void
    {
        $probe = new User(['username' => $newUsername, 'email' => $source->email]);
        if ($probe->project()->hostingExists()) {
            throw ValidationException::withMessages(['new_username' => ProjectNameRefusal::unavailable($newUsername)]);
        }
    }

    /** Destroys the half-made copy, best-effort, and reports the failure as a deploy problem. */
    private function failed(User $copy, \Throwable $e): never
    {
        try {
            $copy->refresh()->project()->destroy();
        } catch (\Throwable) {
        }
        $match = DeployFailureExplainer::match($e->getMessage());
        throw ProblemException::deploy(
            $match['rule'] ?? 'clone_failed',
            $match['message'] ?? $e->getMessage(),
            null
        );
    }
}

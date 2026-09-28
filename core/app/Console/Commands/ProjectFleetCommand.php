<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SelectsProjects;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Do one thing to one project, or to every project on the engine.
 *
 * Six commands wrote this loop out: the same selection preamble, the same
 * per-project try/catch, the same "failed on at least one" exit code. What
 * actually differed was the line printed and the call made, which is what the
 * two abstract methods are.
 *
 * A project that throws is reported and the run continues -- a fleet-wide fix
 * must not stop at the first broken account.
 */
abstract class ProjectFleetCommand extends Command
{
    use SelectsProjects;

    /** The one thing this command does to a project. */
    abstract protected function applyTo(User $user): void;

    /** Printed before the project is touched. */
    abstract protected function progress(User $user): string;

    /** Printed after it succeeds. */
    protected function finished(User $user): string
    {
        return '  Finished.';
    }

    /** Run once before the loop, after the projects are known; a throw here ends the command. */
    protected function beforeAll(): void
    {
    }

    /** Run once after the loop, whether or not every project succeeded. */
    protected function afterAll(): void
    {
    }

    /**
     * Whether a project that threw makes the whole run fail.
     *
     * True everywhere except the Apache module commands, which have always
     * exited 0 even when every project failed. That looks like a bug rather
     * than a decision, but changing it is a separate call: a script reading
     * the exit code would start seeing failures it never saw before.
     */
    protected function failuresAreFatal(): bool
    {
        return true;
    }

    public function handle(): int
    {
        $projects = $this->selectedProjects();
        if ($projects === null) {
            return 1;
        }

        $this->beforeAll();

        $ok = true;
        foreach ($projects as $user) {
            try {
                $this->output->write($this->progress($user) . "\n");
                $this->applyTo($user);
                $this->info($this->finished($user));
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                $ok = false;
            }
        }

        $this->afterAll();

        return $this->failuresAreFatal() ? (int) !$ok : 0;
    }
}

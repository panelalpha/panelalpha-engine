<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Lib\Host\ProjectMemory;
use App\Console\Commands\Concerns\SelectsProjects;
use App\Lib\Limits\LimitSelection;
use App\Lib\Limits\ResourceLimit;
use App\Models\User;
use App\System\Project\Dind;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SetLimits extends Command
{
    use SelectsProjects;

    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:set-limits', 'users:set-limits'];

    protected $signature = '';

    protected $description = 'Set a project\'s resource limits (disk, memory, CPU, bandwidth, inodes, per-feature counts)';

    public function __construct()
    {
        // Built from the same table the values are parsed against, so an added
        // limit cannot be accepted by one and ignored by the other.
        $this->signature = 'project:limit:set' . ProjectOptions::SIGNATURE
            . implode('', array_map(
                static fn (ResourceLimit $l): string => " {--{$l->option}=}",
                ResourceLimit::all()
            ));

        parent::__construct();
    }

    public function handle(): int
    {
        // Options are validated before the project is looked up, so a bad
        // value is reported first whatever the name.
        if (!$this->namesProjects()) {
            return 1;
        }

        $selection = LimitSelection::fromOptions($this->options());

        if ($selection->isEmpty()) {
            $this->error('At least one of following options is required: ' . LimitSelection::optionListHint());

            return 1;
        }

        foreach (LimitSelection::rejections($this->options()) as $reason) {
            $this->error($reason);

            return 1;
        }

        // Memory is the one limit bounded by what the host still has free.
        if ($selection->has('memory_limit')) {
            $problem = ProjectMemory::changeProblem((int) $selection->all()['memory_limit']);
            if ($problem !== null) {
                $this->error($problem['message']);

                return 1;
            }
        }

        $projects = $this->selectedProjects();
        if ($projects === null) {
            return 1;
        }

        $one = $this->option('username') ? $projects[0] : null;
        $this->warn($one !== null
            ? "Following limits will be set for user `{$one->username}`:"
            : 'Following limits will be set for all (' . count($projects) . ') users:');
        // The two headings have always padded their rows differently.
        $this->announce($selection, $one !== null ? 20 : 16);

        return $this->confirm('Do you wish to continue?')
            ? $this->apply($projects, $selection)
            : 0;
    }

    private function announce(LimitSelection $selection, int $width): void
    {
        foreach ($selection->all() as $key => $value) {
            $this->info(str_pad('  ' . $key . ': ', $width) . ResourceLimit::byKey($key)->format($value));
        }
    }

    /** @param array<User>|Collection<int, User> $users */
    private function apply($users, LimitSelection $selection): int
    {
        $appliedLive = 0;

        foreach ($users as $user) {
            foreach ($selection->all() as $key => $value) {
                $user->setLimit(ResourceLimit::byKey($key), $value);
            }
            $user->save();

            // A DinD account takes memory live; anything else waits for its rebuild.
            $runtime = $user->project()->runtime();
            if ($selection->has('memory_limit') && $runtime instanceof Dind) {
                $runtime->applyMemoryLimit();
                $this->info("  {$user->username}: memory limit applied to the running account.");
                $appliedLive++;
            }
        }

        $this->info('Limits have been updated.');
        // Only ask for a rebuild when one is still pending for some account;
        // with no accounts at all, none is.
        $except = $appliedLive === count($users) ? ['memory_limit'] : [];
        if (count($users) > 0 && $selection->needsRebuild($except)) {
            $this->warn('Changes will take effect after rebuild');
        }

        return 0;
    }
}

<?php

namespace App\Console\Commands\Projects;

use App\Http\Requests\ProjectStagingRequest;
use App\Lib\Helper;
use App\Models\Domain;
use App\Models\User;
use App\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectStagingCommand extends Command
{
    protected $aliases = ['users:staging'];

    protected $signature = 'project:staging {project : Live project username} {--new-username=} {--domain=}';

    protected $description = 'Create a linked staging mirror of a live dind project (synchronous)';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        $source = User::findByUsername($username);
        if (! $source) {
            $this->error("No such user: '{$username}'");

            return 1;
        }

        $dest = null;

        try {
            $system = new System;

            $newUsername = $this->stagingUsername($source, $system);
            if ($newUsername === null) {
                return 1;
            }

            $destDomain = $this->stagingDomain($source);
            if ($destDomain === null) {
                return 1;
            }

            if ($this->hostingExists($source, $newUsername, $destDomain)) {
                $this->error('Username not available.');

                return 1;
            }

            ProjectStagingRequest::assertCanCreate($source, $newUsername.'_');
            $dest = $source->makePendingStaging($newUsername, $destDomain, 'artisan');
            $system->projects()->copy($source->username, $dest->username);

            $this->info($dest->username.' '.$dest->domain);

            return 0;
        } catch (\Throwable $e) {
            $this->error(self::failureMessage($e));
            $this->deleteDestIfExists($dest);

            return 1;
        }
    }

    /** The staging username from --new-username or generated, or null once a refusal has been printed. */
    private function stagingUsername(User $source, System $system): ?string
    {
        $newUsername = self::lowercaseOption($this->option('new-username'))
            ?? Helper::generateCloneUsername($source->username);
        if ($newUsername === null) {
            $this->error('Could not generate an available username for the staging project.');

            return null;
        }

        if (User::existsByUsername($newUsername)) {
            $this->error('User already exists.');

            return null;
        }
        if (! $system->isUsernameAvailable($newUsername)) {
            $this->error('Username not available.');

            return null;
        }

        return $newUsername;
    }

    /** The staging domain from --domain (without "www.") or generated, or null once a refusal has been printed. */
    private function stagingDomain(User $source): ?string
    {
        $destDomain = self::lowercaseOption($this->option('domain'));
        if ($destDomain === null) {
            $destDomain = Helper::generateCloneDomain($source->domain);
            if ($destDomain === null) {
                $this->error('Could not generate an available staging domain.');

                return null;
            }
        } elseif (Str::startsWith($destDomain, 'www.')) {
            $destDomain = Str::after($destDomain, 'www.');
        }

        if (Domain::domainOrAliasExists($destDomain)) {
            $this->error('Domain already exists.');

            return null;
        }

        return $destDomain;
    }

    private function hostingExists(User $source, string $newUsername, string $destDomain): bool
    {
        $probe = User::make([
            'username' => $newUsername,
            'domain' => $destDomain,
            'email' => $source->email,
        ]);

        return $probe->project()->hostingExists();
    }

    private static function lowercaseOption(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }

    /** A validation failure is reported by its first message, anything else by its own. */
    private static function failureMessage(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->first() ?? $e->getMessage();
        }

        return $e->getMessage();
    }

    private function deleteDestIfExists(?User $dest): void
    {
        if ($dest === null) {
            return;
        }

        $existing = User::findByUsername($dest->username);
        if ($existing === null) {
            return;
        }

        try {
            $existing->project()->destroy();
        } catch (\Throwable $cleanup) {
            Log::error('project:staging cleanup failed', [
                'dest' => $dest->username,
                'exception' => $cleanup,
            ]);
        }
    }
}

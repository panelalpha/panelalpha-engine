<?php

namespace App\Lib\Project;

use App\Models\User;
use App\System;
use App\System\AccountContainers;
use App\System\Privileged;
use App\System\Project;
use App\System\UsernamePolicy;
use RuntimeException;

/**
 * Names on this server that no project row owns: an account, a home directory, a project directory or a container left
 * by a create or a delete that did not finish. The operator lists and removes them; nothing here runs by itself.
 *
 * Only a name something engine-made holds is an orphan: an account `users.create` marked, a home carrying
 * {@see Project::ENGINE_TRACE} or belonging to such an account, the project's own directory, or a container started
 * from it. Anything else holding the name is the operator's to remove.
 */
final class ProjectOrphans
{
    /** @var array<string, array{uid: int, gid: int, home: string, shell: string, engine: bool}>|null */
    private ?array $accounts = null;

    public function __construct(private readonly System $system) {}

    /**
     * Every name a project could have that something engine-made still holds, with everything that holds it.
     *
     * @return array<string, list<string>> name => the holders {@see System::usernameHolders()} names
     */
    public function all(): array
    {
        $orphans = [];
        $sources = [
            System::HOLDER_ACCOUNT => array_keys($this->accounts ??= $this->system->hostingAccounts()),
            System::HOLDER_HOME => $this->directoryNames($this->system->homesDirPath()),
            System::HOLDER_PROJECT_DIRECTORY => $this->directoryNames($this->system->projectsDirPath()),
            System::HOLDER_CONTAINER => $this->containerNames(),
        ];
        foreach ($sources as $holder => $names) {
            foreach ($names as $name) {
                if (UsernamePolicy::isAcceptable($name) && ! User::existsByUsername($name)) {
                    $orphans[$name][] = $holder;
                }
            }
        }
        $orphans = array_filter(
            $orphans,
            fn (array $holders, string $name): bool => $this->foreignHolders($name, $holders) !== $holders,
            ARRAY_FILTER_USE_BOTH
        );
        ksort($orphans);

        return $orphans;
    }

    /**
     * Remove everything that holds $name: a container started from its project directory first, then the account, its
     * home and the project directory, the account before its home.
     *
     * @throws RuntimeException when a project row owns the name, nothing holds it, or the account, home or container
     *                          holding it was not made by this engine
     */
    public function delete(string $name): void
    {
        if (User::existsByUsername($name)) {
            throw new RuntimeException(
                "A project named '{$name}' still exists, so the name is not an orphan: delete that project to free it."
            );
        }

        $holders = $this->system->usernameHolders($name);
        if ($holders === []) {
            throw new RuntimeException("Nothing on this server holds '{$name}'.");
        }

        $foreign = $this->foreignHolders($name, $holders);
        if ($foreign === $holders) {
            throw new RuntimeException(
                "Nothing this engine made holds '{$name}', so it is not an orphan and nothing was removed. If it should "
                .'go, remove it yourself with '.$this->quotedSteps($name, $foreign).'.'
            );
        }
        if ($foreign !== []) {
            throw new RuntimeException(
                "'{$name}' is held by something this engine did not make, so nothing was removed. Remove it yourself "
                .'with '.$this->quotedSteps($name, $foreign).', then run this again.'
            );
        }

        if (in_array(System::HOLDER_CONTAINER, $holders, true)) {
            $this->system->exec(Privileged::docker()->forceRemoveContainer($name));
        }

        $this->system->project(new User(['username' => $name]))->tearDownLinuxIsolation();
    }

    /**
     * What an operator has to remove themselves because the engine did not make it, as the commands that do it; empty
     * when everything holding the name is the engine's own and this command frees it.
     *
     * @param  list<string>  $holders
     * @return list<string>
     */
    public function foreignSteps(string $name, array $holders): array
    {
        return $this->removalSteps($name, $this->foreignHolders($name, $holders));
    }

    /**
     * @param  list<string>  $holders
     * @return list<string>
     */
    private function removalSteps(string $name, array $holders): array
    {
        return array_map(fn (string $holder): string => match ($holder) {
            System::HOLDER_ACCOUNT => "userdel {$name}",
            System::HOLDER_HOME => 'rm -rf '.$this->system->projectHomeDirPath($name),
            default => "docker rm -f {$name}",
        }, $holders);
    }

    /**
     * The holders of $name this engine did not make. The project directory is always the engine's own.
     *
     * @param  list<string>  $holders
     * @return list<string>
     */
    private function foreignHolders(string $name, array $holders): array
    {
        return array_values(array_filter($holders, fn (string $holder): bool => match ($holder) {
            System::HOLDER_ACCOUNT => ! $this->engineMadeAccount($name),
            System::HOLDER_HOME => ! $this->engineMadeHome($name),
            System::HOLDER_CONTAINER => ! (new AccountContainers($this->system))->ownsContainerNamed($name),
            default => false,
        }));
    }

    private function engineMadeAccount(string $name): bool
    {
        $this->accounts ??= $this->system->hostingAccounts();

        return ($this->accounts[$name]['engine'] ?? false) === true;
    }

    private function engineMadeHome(string $name): bool
    {
        return $this->engineMadeAccount($name) || $this->system->runProcess(
            Privileged::files()->isDirectory($this->system->projectHomeDirPath($name).'/'.Project::ENGINE_TRACE)
        )->isSuccessful();
    }

    /** @param  list<string>  $holders */
    private function quotedSteps(string $name, array $holders): string
    {
        return implode(' and ', array_map(static fn (string $step): string => "`{$step}`", $this->removalSteps($name, $holders)));
    }

    /** @return list<string> */
    private function directoryNames(string $dir): array
    {
        $listing = $this->system->runProcess(Privileged::files()->listAllNames($dir));
        if (! $listing->isSuccessful()) {
            throw new RuntimeException("The names below '{$dir}' could not be listed: ".trim($listing->getErrorOutput()));
        }

        return array_values(array_filter(preg_split('/\R/', trim($listing->getOutput())) ?: []));
    }

    /**
     * The names of the containers Compose started from a project directory.
     *
     * @return list<string>
     */
    private function containerNames(): array
    {
        $listing = $this->system->runProcess(Privileged::docker()->containersLabelled(AccountContainers::WORKING_DIR_LABEL));
        if (! $listing->isSuccessful()) {
            throw new RuntimeException('The host\'s containers could not be listed: '.trim($listing->getErrorOutput()));
        }

        $names = [];
        foreach (preg_split('/\R/', trim($listing->getOutput())) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) !== 3 || dirname($fields[2]) !== $this->system->projectsDirPath()) {
                continue;
            }
            $names[] = basename($fields[2]);
        }

        return $names;
    }
}

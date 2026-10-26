<?php

namespace App\System\Project;

use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Project SFTP collaborator — login lines for this Project and host-wide rebuild.
 */
class Sftp
{
    public function __construct(
        private readonly ProjectAggregate $project,
    ) {}

    public function loginLines(): string
    {
        $osUser = $this->project->syncLinuxUser();
        $home = $this->project->homeDirPath();
        $config = '';
        foreach ($this->project->model()->getSftpAccounts() as $acc) {
            $auth = $acc->getAuthMethods();
            $username = $acc->username;
            $password = in_array('password', $auth) ? $acc->password : '';
            $pubKey = in_array('public_key', $auth) ? $acc->public_key : '';
            $config .= "{$username}:{$password}:{$osUser['UID']}:{$osUser['GID']}:{$home}:{$pubKey}\n";
        }

        return $config;
    }

    public function rebuild(): void
    {
        $config = '';
        foreach ($this->usersWithSftpAccounts() as $user) {
            /** @var ModelsUser $user */
            try {
                $project = $this->project->system()->project($user);
                $config .= $project->sftp()->loginLines();
            } catch (\Exception $e) {
                Log::warning('Could not rebuild SFTP accounts for user '.$user->username, [
                    'exception' => $e,
                ]);
            }
        }

        $this->project->system()->sftp()->applyLogins($config);
    }

    /**
     * @return Collection<int, ModelsUser>
     */
    protected function usersWithSftpAccounts(): Collection
    {
        return ModelsUser::with('sftpAccounts')
            ->whereHas('sftpAccounts')
            ->get();
    }
}

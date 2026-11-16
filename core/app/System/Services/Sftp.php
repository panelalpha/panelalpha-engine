<?php

namespace App\System\Services;

use App\Models\SftpAccount;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;

/**
 * Host SFTP collaborator — logins.conf and sync into the sftp container.
 */
class Sftp
{
    public function __construct(
        private EngineSystem $system,
    ) {}

    public function loginsConfPath(): string
    {
        return $this->system->engineDirPath().'/config/sftp/logins.conf';
    }

    public function writeLogins(string $config): void
    {
        $this->system->filesystem()->filePutContents($this->loginsConfPath(), $config);
    }

    public function syncLogins(): void
    {
        $this->system->runProcess(Privileged::engineServices($this->system->composeFilePath())->sftpSyncLogins());
    }

    public function applyLogins(string $config): void
    {
        $this->writeLogins($config);
        $this->syncLogins();
    }

    /**
     * End every SSH session of these logins and answer how many were open. A login is an
     * `sftp_accounts.username`; the account-name grammar is the last check before it reaches a shell pattern.
     *
     * @param  list<string>  $logins
     */
    public function killSessions(array $logins): int
    {
        $logins = $this->sessionLogins($logins);
        if ($logins === []) {
            return 0;
        }

        $output = $this->system->exec(Privileged::engineServices($this->system->composeFilePath())->sftpKillSessions($logins));

        return (int) trim($output);
    }

    /**
     * {@see killSessions()} for a delete: a host whose sftp container is down must still delete the logins.
     *
     * @param  list<string>  $logins
     */
    public function endSessions(array $logins): void
    {
        try {
            $this->killSessions($logins);
        } catch (\Throwable $e) {
            Log::warning('SFTP session kill failed for '.implode(', ', $logins).': '.$e->getMessage());
        }
    }

    /**
     * @param  list<string>  $logins
     * @return list<string>
     */
    private function sessionLogins(array $logins): array
    {
        $valid = array_filter(
            $logins,
            static fn (mixed $login): bool => is_string($login) && preg_match('/^[a-zA-Z0-9_]+$/', $login) === 1,
        );

        return array_values(array_unique($valid));
    }

    public function rebuildSftpAccounts(): void
    {
        $config = '';
        $users = ModelsUser::with('sftpAccounts')
            ->whereHas('sftpAccounts')
            ->get();

        foreach ($users as $user) {
            /** @var ModelsUser $user */
            try {
                $project = $user->project($this->system);
                $osUser = $project->syncLinuxUser();
                $home = $project->homeDirPath();
                $accs = $user->getSftpAccounts();
                foreach ($accs as $acc) {
                    $config .= self::loginLine($acc, $osUser, $home);
                }
            } catch (\Exception $e) {
                Log::warning('Could not rebuild SFTP accounts for user '.$user->username, [
                    'exception' => $e,
                ]);
            }
        }

        $this->applyLogins($config);
    }

    /**
     * @param  array{UID: int, GID: int}  $osUser
     */
    private static function loginLine(SftpAccount $acc, array $osUser, string $home): string
    {
        $auth = $acc->getAuthMethods();
        $username = $acc->username;
        $password = in_array('password', $auth, true) ? $acc->password : '';
        $pubKey = in_array('public_key', $auth, true) ? $acc->public_key : '';

        return "{$username}:{$password}:{$osUser['UID']}:{$osUser['GID']}:{$home}:{$pubKey}\n";
    }
}

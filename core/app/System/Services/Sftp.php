<?php

namespace App\System\Services;

use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use Illuminate\Support\Facades\Log;

/**
 * Host SFTP collaborator — logins.conf and sync into the sftp container.
 */
class Sftp
{
    public function __construct(
        private EngineSystem $system,
    ) {
    }

    public function loginsConfPath(): string
    {
        return $this->system->engineDirPath() . '/config/sftp/logins.conf';
    }

    public function writeLogins(string $config): void
    {
        $this->system->filesystem()->filePutContents($this->loginsConfPath(), $config);
    }

    public function syncLogins(): void
    {
        $this->system->runProcess([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'sftp',
            'bash',
            '/etc/sftp/sync-logins.sh',
        ]);
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
     * @param list<string> $logins
     */
    public function killSessions(array $logins): int
    {
        $logins = $this->sessionLogins($logins);
        if ($logins === []) {
            return 0;
        }

        $output = $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'sftp',
            'sh',
            '-c',
            $this->killSessionsScript($logins),
        ]);

        return (int) trim($output);
    }

    /**
     * @param list<string> $logins
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

    /**
     * One sshd monitor process watches each session, so the monitors count the sessions; TERM ends them, KILL only
     * what outlives the two-second grace. Never by uid: every account of a project shares one.
     *
     * @param list<string> $logins
     */
    private function killSessionsScript(array $logins): string
    {
        $patterns = [];
        $monitors = [];
        foreach ($logins as $login) {
            $patterns[] = escapeshellarg('^sshd: ' . $login . '(@|\s|$)');
            $monitors[] = escapeshellarg('^sshd: ' . $login . ' \\[priv\\]');
        }

        $all = implode(' ', $patterns);
        $watched = implode(' ', $monitors);

        return implode("\n", [
            'killed=0',
            "for pattern in {$watched}; do",
            '  n=$(pgrep -cf "$pattern" 2>/dev/null || true)',
            '  killed=$((killed + ${n:-0}))',
            'done',
            "for pattern in {$all}; do",
            '  pids=$(pgrep -f "$pattern" 2>/dev/null || true)',
            '  [ -n "$pids" ] && kill -TERM $pids 2>/dev/null || true',
            'done',
            'sleep 2',
            "for pattern in {$all}; do",
            '  pids=$(pgrep -f "$pattern" 2>/dev/null || true)',
            '  [ -n "$pids" ] && kill -KILL $pids 2>/dev/null || true',
            'done',
            'echo "$killed"',
        ]);
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
                $osUser = $user->project()->syncLinuxUser();
                $home = $user->project()->homeDirPath();
                $accs = $user->getSftpAccounts();
                foreach ($accs as $acc) {
                    $auth = $acc->getAuthMethods();
                    $username = $acc->username;
                    $password = in_array('password', $auth, true) ? $acc->password : '';
                    $pubKey = in_array('public_key', $auth, true) ? $acc->public_key : '';
                    $config .= "{$username}:{$password}:{$osUser['UID']}:{$osUser['GID']}:{$home}:{$pubKey}\n";
                }
            } catch (\Exception $e) {
                Log::warning('Could not rebuild SFTP accounts for user ' . $user->username, [
                    'exception' => $e,
                ]);
            }
        }

        $this->applyLogins($config);
    }
}

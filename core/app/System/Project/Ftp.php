<?php

namespace App\System\Project;

use App\Exceptions\DockerErrorException;
use App\System\Project as UserProject;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Ftp
{
    public function __construct(
        private readonly UserProject $project,
    ) {
    }

    public function create(string $ftpUser, string $password, string $directory, ?int $quota = null): void
    {
        $directory = trim($directory, '/');

        $home = $this->project->homeDirPath();
        $hostPath = $home . '/' . $directory;
        if (!File::isDirectory($hostPath) || !self::staysInside($home, $directory, $hostPath)) {
            throw ValidationException::withMessages([
                'directory invalid',
            ]);
        }

        $containerPath = '/home/ftpuser/' . $this->project->username() . '/' . $directory;
        $model = $this->project->model();
        $uid = $model->getUid() ?? 33;
        $gid = $model->getGid() ?? 33;

        $this->project->system()->ftp()->useradd(
            $ftpUser,
            $password,
            $uid,
            $gid,
            $containerPath,
            $quota,
        );
    }

    /**
     * The FTP container mounts every home, so the account is chrooted into
     * this path: `..` or a symlink out of the home would be another tenant's.
     */
    public static function staysInside(string $home, string $directory, string $hostPath): bool
    {
        if (preg_match('#(?:^|/)\.\.?(?:/|$)#', $directory) === 1 || str_contains($directory, "\0")) {
            return false;
        }
        $realHome = realpath($home);
        $real = realpath($hostPath);
        if ($realHome === false || $real === false) {
            return false;
        }

        return $real === $realHome || str_starts_with($real, rtrim($realHome, '/') . '/');
    }

    /**
     * @throws DockerErrorException
     */
    public function update(string $ftpUser, ?string $password = null, ?int $quota = null): void
    {
        $pureFtpd = $this->project->system()->ftp();

        if (!empty($password)) {
            $pureFtpd->passwd($ftpUser, $password);
        }

        $pureFtpd->usermodQuota($ftpUser, $quota);
        $pureFtpd->exec(['pure-pw', 'mkdb']);
    }

    /**
     * @throws DockerErrorException
     */
    public function delete(string $ftpUser): void
    {
        $pureFtpd = $this->project->system()->ftp();
        if ($pureFtpd->userExists($ftpUser)) {
            $pureFtpd->deleteUser($ftpUser);
        }
        $pureFtpd->reload();
    }

    /**
     * @param string[] $accounts
     * @throws DockerErrorException
     */
    public function deleteMany(array $accounts): void
    {
        $pureFtpd = $this->project->system()->ftp();
        foreach ($accounts as $acc) {
            if ($pureFtpd->userExists($acc)) {
                $pureFtpd->deleteUser($acc);
            }
        }
        $pureFtpd->reload();
    }

    /**
     * @throws DockerErrorException
     */
    public function diskUsage(string $directory): string
    {
        $hostPath = $this->project->homeDirPath() . '/' . ltrim($directory, '/');
        $system = $this->project->system();
        if (!$system->filesystem()->directoryExists($hostPath)) {
            $system->runProcess(['sudo', 'mkdir', '-p', $hostPath]);
            return '0';
        }
        $result = $system->exec(['sudo', 'du', '-shm', $hostPath]);

        return Str::before($result, "\t");
    }
}

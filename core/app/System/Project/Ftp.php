<?php

namespace App\System\Project;

use App\Exceptions\DockerErrorException;
use App\System\Project as UserProject;
use App\System\Services\PureFtpd;
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
                'directory' => 'The directory must be an existing directory inside the project home.',
            ]);
        }

        $containerPath = '/home/ftpuser/' . $this->project->username() . '/' . $directory;
        $model = $this->project->model();
        [$uid, $gid] = $model->linuxIds();

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
     * The stat cache is cleared first: in PHP-FPM a directory since swapped for a link would still resolve as the directory.
     */
    public static function staysInside(string $home, string $directory, string $hostPath): bool
    {
        if (preg_match('#(?:^|/)\.\.?(?:/|$)#', $directory) === 1 || str_contains($directory, "\0")) {
            return false;
        }
        clearstatcache(true);
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
        $this->deleteIfExists($pureFtpd, $ftpUser);
        $pureFtpd->reload();
    }

    /**
     * The ftp container is an optional profile: a host without it throws
     * on reload, so it is touched only when an account was deleted.
     *
     * @param string[] $accounts
     * @throws DockerErrorException
     */
    public function deleteMany(array $accounts): void
    {
        if ($accounts === []) {
            return;
        }

        $pureFtpd = $this->project->system()->ftp();
        $deleted = false;
        foreach ($accounts as $acc) {
            $deleted = $this->deleteIfExists($pureFtpd, $acc) || $deleted;
        }
        if ($deleted) {
            $pureFtpd->reload();
        }
    }

    /** @return bool whether $ftpUser existed and was deleted */
    private function deleteIfExists(PureFtpd $pureFtpd, string $ftpUser): bool
    {
        if (!$pureFtpd->userExists($ftpUser)) {
            return false;
        }
        $pureFtpd->deleteUser($ftpUser);

        return true;
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

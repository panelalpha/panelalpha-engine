<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\Runtime\Php\AccountUserFiles;
use App\Models\User;
use App\System\Project\Dind as DindProject;

/**
 * The passwd and group files a PHP deploy mounts beside its run file
 * ({@see AccountUserFiles}), brought over from another project by a staging,
 * clone or push.
 *
 * Their `app` entry names the source account's uid and gid, so the copy's
 * container would run as an account that owns none of the copy's files.
 * The source's entry is taken out, which leaves the image's own files, and
 * the entry for this project is added back by the code the deploy writes it
 * with.
 */
final class CopiedAccountUserFiles
{
    private const MODE = '644';

    public function __construct(private DindProject $project) {}

    public function repointFrom(User $source, string $projectDir): void
    {
        $to = $this->project->userModel();
        $fromUid = $source->getUid();
        $fromGid = $source->getGid();
        $uid = $to->getUid();
        $gid = $to->getGid();
        if ($fromUid === null || $fromGid === null || $uid === null || $gid === null) {
            return;
        }
        $dir = rtrim($projectDir, '/').'/';
        $this->rewrite($dir.EngineArtifacts::RUN_PASSWD, fn (string $passwd): string => self::passwdFor($passwd, $fromUid, $uid, $gid));
        $this->rewrite($dir.EngineArtifacts::RUN_GROUP, fn (string $group): string => self::groupFor($group, $fromGid, $gid));
    }

    public static function passwdFor(string $passwd, int $fromUid, int $uid, int $gid): string
    {
        $base = self::withoutAppEntry($passwd, $fromUid);

        return $base === $passwd ? $passwd : (AccountUserFiles::passwdWith($base, $uid, $gid) ?? $base);
    }

    public static function groupFor(string $group, int $fromGid, int $gid): string
    {
        $base = self::withoutAppEntry($group, $fromGid);

        return $base === $group ? $group : (AccountUserFiles::groupWith($base, $gid) ?? $base);
    }

    /**
     * @param  \Closure(string): string  $rewritten
     */
    private function rewrite(string $path, \Closure $rewritten): void
    {
        $system = $this->project->system();
        if (! $system->filesystem()->fileExists($path)) {
            return;
        }
        if ($system->runProcess(['sudo', 'test', '-L', $path])->isSuccessful()) {
            throw new \RuntimeException("Refusing to rewrite '{$path}': it is a symlink.");
        }
        $contents = $system->filesystem()->fileGetContents($path);
        $updated = $rewritten($contents);
        if ($updated !== $contents) {
            $system->filesystem()->writeFileReplacingPath($path, $updated, $this->project->userModel()->getChownString(), self::MODE);
        }
    }

    /** $file without the `app` line whose id field is $id. */
    private static function withoutAppEntry(string $file, int $id): string
    {
        $lines = preg_split('/\r?\n/', $file) ?: [];
        $kept = array_filter($lines, static fn (string $line): bool => ! self::isAppEntry($line, $id));

        return count($kept) === count($lines) ? $file : implode("\n", $kept);
    }

    private static function isAppEntry(string $line, int $id): bool
    {
        $fields = explode(':', $line);

        return count($fields) >= 3 && $fields[0] === AccountUserFiles::USER_NAME && $fields[2] === (string) $id;
    }
}

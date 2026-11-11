<?php

namespace App\Lib\Upload;

use App\Exceptions\ProblemException;
use App\Lib\Net\EgressGuard;
use App\Lib\Project\ArchiveDrop;
use App\Models\User;

/**
 * The archive an archive deploy imports, made a file in the project home and removed afterwards if put there for
 * the deploy. A URL is checked against the outbound guard before anything is stored.
 */
class ArchiveSources
{
    public const UPLOAD_GONE = 'upload_gone';

    public function __construct(
        private readonly Uploads $uploads,
        private readonly ArchiveFetch $fetch,
        private readonly ArchiveDrop $drop,
    ) {}

    /**
     * Refused before anything is stored: a URL the outbound guard would not let the download reach.
     *
     * @throws ProblemException `host_refused` on `source.url`
     */
    public static function assertReachable(?string $url): void
    {
        $problem = $url === null ? null : EgressGuard::problem('source.url', $url);
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }

    /**
     * @return string the archive's path in the project, as an archive deploy takes it
     *
     * @throws ProblemException `archive_download_failed` on `source.url`, `upload_gone` on `source.upload_id`
     */
    public function materialise(User $user, ArchiveSource $source): string
    {
        return match ($source->kind) {
            ArchiveSource::UPLOAD => $this->uploaded($user, $source->value),
            ArchiveSource::URL => $this->downloaded($user, $source->value),
            default => $source->value,
        };
    }

    /** Removed as the CLI's own archive is ({@see ArchiveDrop::discard()}): a failed removal does not change how the deploy ended. */
    public function release(User $user, ArchiveSource $source, string $zipPath): void
    {
        if ($source->isDropped()) {
            $this->drop->discard($user, $zipPath);
        }
    }

    private function uploaded(User $user, string $uploadId): string
    {
        $upload = $this->uploads->find($uploadId);
        if ($upload === null || $upload->claimed_by !== $user->username) {
            throw ProblemException::one('source.upload_id', self::UPLOAD_GONE,
                "Upload {$uploadId} is gone or no longer this project's; upload the archive again.");
        }

        return $this->uploads->putInto($user, $upload);
    }

    private function downloaded(User $user, string $url): string
    {
        try {
            return $this->fetch->into($user, $url);
        } catch (\Exception $e) {
            throw ProblemException::one('source.url', 'archive_download_failed', ArchiveSource::withShownUrls($e->getMessage()));
        }
    }
}

<?php

namespace App\Lib\Upload;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Lib\Project\ArchiveDrop;
use App\Models\Task;
use App\Models\Upload;
use App\Models\User;
use App\Rules\ArchiveFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Archives uploaded before their project exists, kept in the engine's storage until a create or a source attach takes
 * them by id; one left unused expires.
 */
class Uploads
{
    public const NOT_FOUND = 'upload_not_found';

    public const EXPIRED = 'upload_expired';

    public const TTL_HOURS = 24;

    private const DIRECTORY = 'uploads';

    /** The archive formats an archive deploy imports, by file name. */
    public const ARCHIVE_SUFFIXES = ['.tar.gz', '.tgz', '.zip'];

    /** An archive, checked by {@see ArchiveFileName} before it reaches here. */
    public function store(UploadedFile $file): Upload
    {
        $name = self::fileName($file->getClientOriginalName());
        $id = Upload::PREFIX.Str::lower((string) Str::ulid());
        $size = (int) $file->getSize();
        $file->move(self::root().'/'.$id, $name);

        return Upload::create([
            'id' => $id,
            'filename' => $name,
            'size' => $size,
            'path' => $id.'/'.$name,
            'created_by_token_id' => Task::currentTokenId(),
            'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
        ]);
    }

    public function find(string $id): ?Upload
    {
        return str_starts_with($id, Upload::PREFIX) ? Upload::query()->find($id) : null;
    }

    /**
     * An upload that is still there: not moved into a project, not expired.
     *
     * @throws NotFoundException
     */
    public function findOrFail(string $id): Upload
    {
        $upload = $this->find($id);
        if ($upload === null || $upload->expired()) {
            throw new NotFoundException("No upload {$id}.", Upload::class);
        }

        return $upload;
    }

    /**
     * Removed with its file, unless a create already claimed it: that
     * create's deploy moves the file into its project.
     *
     * @throws NotFoundException|ProblemException 409 `upload_claimed`
     */
    public function deleteUnclaimed(string $id): void
    {
        $upload = $this->findOrFail($id);
        if ($upload->claimed_by !== null) {
            throw ProblemException::conflict('upload', 'upload_claimed', "Upload {$upload->id} is claimed by project '{$upload->claimed_by}'.");
        }

        $this->delete($upload);
    }

    /**
     * The upload `$id` names, if a create may use it: present, not expired, not claimed.
     *
     * @throws ProblemException on `$field`
     */
    public function usable(string $id, string $field): Upload
    {
        $upload = $this->find($id);
        if ($upload === null || ! is_file($this->absolutePath($upload))) {
            throw ProblemException::one($field, self::NOT_FOUND, "No upload {$id}. Upload the archive first and pass the id it returns.");
        }
        if ($upload->expired()) {
            throw ProblemException::one($field, self::EXPIRED, "Upload {$id} expired at {$upload->expires_at->toIso8601String()}. Upload the archive again.");
        }
        if ($upload->claimed_by !== null) {
            throw ProblemException::one($field, 'upload_in_use', "Upload {$id} is already used by project '{$upload->claimed_by}'.");
        }

        return $upload;
    }

    /** Conditional, so two creates naming one upload cannot both have it. */
    public function claim(Upload $upload, string $by): bool
    {
        $claimed = Upload::query()->whereKey($upload->id)->whereNull('claimed_by')->update(['claimed_by' => $by]);
        if ($claimed === 1) {
            $upload->claimed_by = $by;
        }

        return $claimed === 1;
    }

    /**
     * The upload `$id` names, claimed by `$by`: refused as {@see usable()} refuses, and when another create claimed it first.
     *
     * @throws ProblemException on `$field`
     */
    public function claimUsable(string $id, string $field, string $by): Upload
    {
        $upload = $this->usable($id, $field);
        if (! $this->claim($upload, $by)) {
            throw ProblemException::one($field, 'upload_in_use', "Upload {$upload->id} was just claimed by another create.");
        }

        return $upload;
    }

    public function handOver(Upload $upload, string $from, string $to): void
    {
        Upload::query()->whereKey($upload->id)->where('claimed_by', $from)->update(['claimed_by' => $to]);
    }

    public function release(Upload $upload, string $by): void
    {
        Upload::query()->whereKey($upload->id)->where('claimed_by', $by)->update(['claimed_by' => null]);
    }

    /**
     * Into the project for an archive deploy, written as the account; the
     * upload is gone afterwards.
     *
     * @return string the archive's path in the project, as an archive deploy takes it
     */
    public function putInto(User $user, Upload $upload): string
    {
        $zipPath = app(ArchiveDrop::class)->put($user, $this->absolutePath($upload), $upload->filename, ArchiveDrop::PROJECT_DIR);
        $this->delete($upload);

        return $zipPath;
    }

    public function delete(Upload $upload): void
    {
        $path = $this->absolutePath($upload);
        if (is_file($path)) {
            @unlink($path);
        }
        @rmdir(dirname($path));
        $upload->delete();
    }

    /** Uploads past their expiry, removed with their files; the number removed. */
    public function prune(): int
    {
        $removed = 0;
        Upload::query()->where('expires_at', '<', Carbon::now())->each(function (Upload $upload) use (&$removed): void {
            $this->delete($upload);
            $removed++;
        });

        return $removed;
    }

    public function absolutePath(Upload $upload): string
    {
        return self::root().'/'.$upload->path;
    }

    public static function suffixOf(string $name): string
    {
        foreach (self::ARCHIVE_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($name), $suffix)) {
                return $suffix;
            }
        }

        return '';
    }

    private static function root(): string
    {
        return storage_path('app/'.self::DIRECTORY);
    }

    /** Whether a client's file name says it is an archive the deploy can import. */
    public static function isArchiveName(string $clientName): bool
    {
        $name = self::fileName($clientName);

        return self::suffixOf($name) !== '' && preg_match('/^[^\x00-\x1f\/]{1,200}$/', $name) === 1;
    }

    /** The client's file name without any path it came with. */
    private static function fileName(string $clientName): string
    {
        return trim(basename(str_replace('\\', '/', $clientName)));
    }
}

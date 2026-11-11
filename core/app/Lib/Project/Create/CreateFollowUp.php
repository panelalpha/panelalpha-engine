<?php

namespace App\Lib\Project\Create;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Upload\ArchiveSource;
use App\Lib\Upload\ArchiveSources;
use App\Lib\Upload\Uploads;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a typed create does once the account exists, in the same task: an archive source deploys it, a WordPress
 * source installs WordPress. The admin password is encrypted, since the queue keeps job payloads in the database.
 */
final class CreateFollowUp
{
    /** Claims an upload between the checks before provisioning and the account that will own it. */
    private ?string $claimToken = null;

    /** @param array<string, mixed> $data */
    private function __construct(public readonly string $sourceType, private readonly array $data) {}

    public static function for(SourceSpec $source): self
    {
        return match ($source->type) {
            CreateShape::SOURCE_ARCHIVE => new self($source->type, array_filter([
                'upload_id' => $source->uploadId(),
                'url' => $source->archiveUrl(),
            ])),
            CreateShape::SOURCE_WORDPRESS => new self($source->type, self::sealedWordpress($source->wordpress())),
            default => new self($source->type, []),
        };
    }

    /** @param ?array<string, mixed> $data */
    public static function fromArray(?array $data): ?self
    {
        if (! is_array($data) || ! is_string($data['source_type'] ?? null)) {
            return null;
        }
        $type = $data['source_type'];
        unset($data['source_type']);

        return new self($type, $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['source_type' => $this->sourceType] + $this->data;
    }

    /**
     * Checked before anything is provisioned, so an unusable upload or URL
     * leaves no account behind. An upload is claimed here, for this create.
     *
     * @throws ProblemException
     */
    public function assertUsable(): void
    {
        if (isset($this->data['url'])) {
            ArchiveSources::assertReachable((string) $this->data['url']);
        }
        if (isset($this->data['upload_id'])) {
            $this->claimUpload();
        }
    }

    /** The account exists: the upload this create claimed becomes that project's. */
    public function claimFor(User $user): void
    {
        $upload = $this->upload();
        if ($upload !== null && $this->claimToken !== null) {
            app(Uploads::class)->handOver($upload, $this->claimToken, $user->username);
            $this->claimToken = null;
        }
    }

    /** A create that failed after the checks lets the upload go again. */
    public function release(): void
    {
        $upload = $this->upload();
        if ($upload !== null && $this->claimToken !== null) {
            app(Uploads::class)->release($upload, $this->claimToken);
            $this->claimToken = null;
        }
    }

    /**
     * What the project records as its source, for GET /projects/{name}; null
     * when its own details already say it (git) or there is none.
     *
     * @return ?array<string, mixed>
     */
    public function sourceDetails(): ?array
    {
        return match ($this->sourceType) {
            CreateShape::SOURCE_ARCHIVE => $this->archive()->recorded(),
            CreateShape::SOURCE_WORDPRESS => ['type' => CreateShape::SOURCE_WORDPRESS],
            default => null,
        };
    }

    /** @return array<string, mixed> what the create task shows of its source */
    public function taskDetails(): array
    {
        $archive = $this->sourceType === CreateShape::SOURCE_ARCHIVE ? $this->archive()->taskDetails() : [];

        return ['source_type' => $this->sourceType, ...$archive];
    }

    /**
     * The work after the account's own deploy, under the same deploy log. A failed WordPress install takes the new
     * account with it, as a failed classic create does.
     *
     * @param  callable(string): void  $log  a line for the task log
     */
    public function run(User $user, ?DeployLogger $logger, callable $log): void
    {
        match ($this->sourceType) {
            CreateShape::SOURCE_ARCHIVE => app(ProjectRebuild::class)->run($user, ProjectRebuild::DEPLOY_ARCHIVE, $logger, null, [], $this->archive()),
            CreateShape::SOURCE_WORDPRESS => $this->installWordpress($user, $log),
            default => null,
        };
    }

    private function archive(): ArchiveSource
    {
        return isset($this->data['url'])
            ? ArchiveSource::url((string) $this->data['url'])
            : ArchiveSource::upload((string) ($this->data['upload_id'] ?? ''));
    }

    /** @param callable(string): void $log */
    private function installWordpress(User $user, callable $log): void
    {
        try {
            app(FreshWordpress::class)->install($user, $this->openedWordpress(), $log);
        } catch (Throwable $e) {
            self::removeProject($user);
            throw $e;
        }
    }

    private static function removeProject(User $user): void
    {
        try {
            $user->project()->destroy();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @throws ProblemException */
    private function claimUpload(): void
    {
        $token = '#'.Str::random(20);
        app(Uploads::class)->claimUsable((string) $this->data['upload_id'], 'source.upload_id', $token);
        $this->claimToken = $token;
    }

    private function upload(): ?Upload
    {
        $id = $this->data['upload_id'] ?? null;

        return is_string($id) ? app(Uploads::class)->find($id) : null;
    }

    /**
     * @param  array<string, ?string>  $site
     * @return array<string, ?string>
     */
    private static function sealedWordpress(array $site): array
    {
        $site['admin_password'] = Crypt::encryptString((string) $site['admin_password']);

        return $site;
    }

    /**
     * @return array{title: string, admin_user: string, admin_email: string, admin_password: string, locale: ?string, version: ?string}
     */
    private function openedWordpress(): array
    {
        $site = $this->data;

        return [
            'title' => (string) ($site['title'] ?? ''),
            'admin_user' => (string) ($site['admin_user'] ?? ''),
            'admin_email' => (string) ($site['admin_email'] ?? ''),
            'admin_password' => Crypt::decryptString((string) ($site['admin_password'] ?? '')),
            'locale' => is_string($site['locale'] ?? null) ? $site['locale'] : null,
            'version' => is_string($site['version'] ?? null) ? $site['version'] : null,
        ];
    }
}

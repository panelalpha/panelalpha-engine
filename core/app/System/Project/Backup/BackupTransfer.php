<?php

namespace App\System\Project\Backup;

use App\Integrations\Storage\BackupStorage;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Support\JobDeadline;
use Illuminate\Support\Collection;

/** Moves a backup's items between the staging directory and its container, checksummed. */
final class BackupTransfer
{
    public function __construct(
        private BackupRecord $record,
        private JobDeadline $deadline,
    ) {}

    public static function prefix(BackupContainer $container, string $username, int $backupId): string
    {
        return self::credentialPrefix($container).$username.'/'.$backupId.'/';
    }

    /**
     * @param  list<array{path: string, file: string, type: string, name: string, scope?: string, docker_name?: string}>  $files
     */
    public function upload(BackupContainer $container, BackupStorage $storage, array $files): void
    {
        $keyPrefix = self::prefix($container, $this->record->username, $this->record->id);

        foreach ($files as $file) {
            $this->deadline->assertTimeLeft();
            $sha256 = $this->sha256Of($file['path']);
            $sizeBytes = filesize($file['path']);
            if ($sizeBytes === false) {
                throw new \RuntimeException('Failed to read staging file size: '.$file['path']);
            }

            $key = $keyPrefix.$file['file'];
            $this->putFile($storage, $key, $file['path']);
            $this->saveItem($key, $sizeBytes, $file, $sha256);
        }
    }

    /**
     * @param  Collection<int, BackupItem>  $items
     * @return array<int, string>
     */
    public function download(Collection $items, BackupStorage $storage, string $stagingDir): array
    {
        $stagedPaths = [];

        foreach ($items as $item) {
            $this->deadline->assertTimeLeft();
            $localPath = $stagingDir.basename($item->remote_path);
            $this->downloadFile($storage, $item->remote_path, $localPath);
            $this->assertChecksum($item, $localPath);
            $stagedPaths[$item->id] = $localPath;
        }

        return $stagedPaths;
    }

    private function putFile(BackupStorage $storage, string $key, string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open staging file: '.$path);
        }

        try {
            $storage->put($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  array{type: string, name: string, scope?: string, docker_name?: string}  $file
     */
    private function saveItem(string $key, int $sizeBytes, array $file, string $sha256): void
    {
        $details = [
            'type' => $file['type'],
            'name' => $file['name'],
            'sha256' => $sha256,
        ];
        if (isset($file['docker_name'])) {
            $details['docker_name'] = $file['docker_name'];
        }
        if (isset($file['scope'])) {
            $details['scope'] = $file['scope'];
        }

        $item = new BackupItem;
        $item->backup_id = $this->record->id;
        $item->remote_path = $key;
        $item->size_bytes = $sizeBytes;
        $item->details = $details;
        $item->save();
    }

    private function downloadFile(BackupStorage $storage, string $remotePath, string $localPath): void
    {
        $stream = $storage->readStream($remotePath);
        $dest = fopen($localPath, 'wb');
        if ($dest === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException('Failed to open staging file: '.$localPath);
        }

        try {
            stream_copy_to_stream($stream, $dest);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($dest)) {
                fclose($dest);
            }
        }
    }

    private function assertChecksum(BackupItem $item, string $localPath): void
    {
        $expectedSha256 = $item->details['sha256'] ?? null;
        if (! is_string($expectedSha256) || $expectedSha256 === '') {
            throw new \RuntimeException('Backup item is missing sha256 checksum.');
        }

        if (! hash_equals($expectedSha256, $this->sha256Of($localPath))) {
            throw new \RuntimeException('Checksum mismatch');
        }
    }

    private function sha256Of(string $path): string
    {
        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new \RuntimeException('Failed to hash staging file: '.$path);
        }

        return $sha256;
    }

    private static function credentialPrefix(BackupContainer $container): string
    {
        $credentials = $container->credentials;
        if (! is_array($credentials)) {
            return '';
        }

        $prefix = $credentials['prefix'] ?? '';
        if (! is_string($prefix) || $prefix === '') {
            return '';
        }

        return rtrim($prefix, '/').'/';
    }
}

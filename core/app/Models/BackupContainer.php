<?php

namespace App\Models;

use App\Integrations\Storage\BackupStorage;
use App\Integrations\Storage\Ftp;
use App\Integrations\Storage\Local;
use App\Integrations\Storage\S3;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string $location
 * @property ?array $credentials
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Collection<int, Backup> $backups
 *
 * @method static ?BackupContainer find(int $id)
 * @method static BackupContainer findOrFail(int $id)
 */
class BackupContainer extends Model
{
    protected $fillable = [
        'name',
        'driver',
        'location',
        'credentials',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
    ];

    public static function findByIdOrName(string $idOrName): ?self
    {
        if (ctype_digit($idOrName)) {
            /** @var ?self */
            $byId = self::query()->find((int) $idOrName);
            if ($byId !== null) {
                return $byId;
            }
        }

        /** @var ?self */
        return self::query()->where('name', $idOrName)->first();
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class, 'container_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class, 'container_id');
    }

    public function storage(): BackupStorage
    {
        return match ($this->driver) {
            'local' => Local::fromContainer($this),
            's3' => S3::fromContainer($this),
            'ftp', 'ftps', 'sftp' => Ftp::fromContainer($this),
            default => throw new InvalidArgumentException(
                "Unsupported backup storage driver: {$this->driver}"
            ),
        };
    }
}

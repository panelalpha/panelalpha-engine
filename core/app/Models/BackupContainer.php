<?php

namespace App\Models;

use App\Integrations\Storage\AbstractStorage;
use App\Integrations\Storage\BackupStorage;
use App\Integrations\Storage\Ftp;
use App\Integrations\Storage\Local;
use App\Integrations\Storage\S3;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string $location
 * @property bool $is_default
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
    /** @var array<string, class-string<AbstractStorage>> */
    private const STORAGE_CLASSES = [
        'local' => Local::class,
        's3' => S3::class,
        'ftp' => Ftp::class,
        'ftps' => Ftp::class,
        'sftp' => Ftp::class,
    ];

    /**
     * Credential keys per remote driver: the ones that must be present, the ones
     * a `vault:<key>` may stand for, and every one kept.
     */
    public const CREDENTIAL_KEYS = [
        's3' => [
            'required' => ['access_key_id', 'secret_access_key'],
            'secrets' => ['secret_access_key'],
            'masked' => ['access_key_id', 'secret_access_key'],
            'kept' => ['access_key_id', 'secret_access_key', 'region', 'endpoint', 'use_path_style_endpoint', 'prefix'],
        ],
        'ftp' => [
            'required' => ['host', 'username'],
            'secrets' => ['password'],
            'masked' => ['password'],
            'kept' => ['host', 'username', 'password', 'port'],
        ],
        'ftps' => [
            'required' => ['host', 'username'],
            'secrets' => ['password'],
            'masked' => ['password'],
            'kept' => ['host', 'username', 'password', 'port'],
        ],
        'sftp' => [
            'required' => ['host', 'username'],
            'secrets' => ['password', 'private_key', 'passphrase'],
            'masked' => ['password', 'private_key', 'passphrase'],
            'kept' => ['host', 'username', 'password', 'private_key', 'passphrase', 'port'],
        ],
    ];

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
        'is_default' => 'boolean',
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

    /** A disabled schedule names a store it no longer backs up into, so the store's deletion takes it along. */
    public function forgetDisabledSchedules(): void
    {
        $this->schedules()->where('enabled', false)->delete();
    }

    /**
     * Deletes the container and the disabled schedules it leaves behind in one transaction, so a refused delete keeps
     * them all; its storage driver then releases what the location still holds for it.
     */
    public function deleteWithEmptyLocation(): void
    {
        DB::transaction(function (): void {
            $wasDefault = $this->is_default;
            $this->forgetDisabledSchedules();
            $this->delete();
            if ($wasDefault) {
                $this->promoteOldestToDefault();
            }
        });
        $this->storageClass()::releaseLocation($this);
    }

    /** The store named by id or name, or the default one when none is named. */
    public static function namedOrDefault(?string $idOrName): ?self
    {
        return $idOrName === null ? self::defaultContainer() : self::findByIdOrName($idOrName);
    }

    /** The store a caller names no other one for, null while the host has none. */
    public static function defaultContainer(): ?self
    {
        return self::query()->where('is_default', true)->orderBy('id')->first();
    }

    /** Makes this store the only default one. */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            self::query()->whereKeyNot($this->getKey())->where('is_default', true)->update(['is_default' => false]);
            $this->forceFill(['is_default' => true])->save();
        });
    }

    /** The first store on a host is the default one, so a panel finds somewhere to back up into. */
    public function claimDefaultWhenNone(): void
    {
        if (! $this->is_default && self::defaultContainer() === null) {
            $this->makeDefault();
        }
    }

    /** A host that lost its default store hands the flag to the oldest one left. */
    private function promoteOldestToDefault(): void
    {
        $next = self::query()->orderBy('id')->first();
        $next?->makeDefault();
    }

    /**
     * The credential values to mask in a message: the secrets, and identifiers that still name the account, such as
     * the S3 access key id a driver's error echoes back. Separate from the vault secrets, which only resolve input.
     *
     * @return list<string>
     */
    public function maskedInMessages(): array
    {
        $keys = self::CREDENTIAL_KEYS[$this->driver]['masked'] ?? [];
        $values = array_intersect_key($this->credentials ?? [], array_flip($keys));

        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    public function storage(): BackupStorage
    {
        return $this->storageClass()::fromContainer($this);
    }

    /** @return class-string<AbstractStorage> */
    private function storageClass(): string
    {
        return self::STORAGE_CLASSES[$this->driver]
            ?? throw new InvalidArgumentException("Unsupported backup storage driver: {$this->driver}");
    }
}

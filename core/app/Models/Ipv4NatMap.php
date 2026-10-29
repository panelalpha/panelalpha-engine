<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $local_ip
 * @property string $public_ip
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static ?Ipv4NatMap find(int $id)
 * @method static Ipv4NatMap findOrFail(int $id)
 * @method static Ipv4NatMap create(array $params)
 * @method static Collection<Ipv4NatMap> get()
 */
class Ipv4NatMap extends Model
{
    protected $table = 'ipv4_nat_maps';

    protected $fillable = [
        'local_ip',
        'public_ip',
    ];

    private static ?array $localToPublicMap = null;

    private static ?array $publicToLocalMap = null;

    private static ?bool $natModeEnabledOverride = null;

    /**
     * @return array<string, string>
     */
    public static function getLocalToPublicMap(): array
    {
        if (self::$localToPublicMap !== null) {
            return self::$localToPublicMap;
        }
        $map = [];
        foreach (self::all() as $record) {
            $map[$record->local_ip] = $record->public_ip;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     */
    public static function setLocalToPublicMap(array $map): void
    {
        self::$localToPublicMap = $map;
    }

    /**
     * @return array<string, string>
     */
    public static function getPublicToLocalMap(): array
    {
        if (self::$publicToLocalMap !== null) {
            return self::$publicToLocalMap;
        }
        $map = [];
        foreach (self::all() as $record) {
            $map[$record->public_ip] = $record->local_ip;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     */
    public static function setPublicToLocalMap(array $map): void
    {
        self::$publicToLocalMap = $map;
    }

    public static function setNatModeEnabledOverride(bool $enabled): void
    {
        self::$natModeEnabledOverride = $enabled;
    }

    public static function clearNatModeEnabledOverride(): void
    {
        self::$natModeEnabledOverride = null;
    }

    public static function isNatModeEnabled(): bool
    {
        if (self::$natModeEnabledOverride !== null) {
            return self::$natModeEnabledOverride;
        }

        return self::count() > 0;
    }

    public static function upsertMap(string $localIp, string $publicIp): self
    {
        self::$localToPublicMap = null;
        self::$publicToLocalMap = null;

        /** @var self */
        return self::updateOrCreate(
            ['local_ip' => $localIp],
            ['public_ip' => $publicIp],
        );
    }

    public function delete(): ?bool
    {
        self::$localToPublicMap = null;
        self::$publicToLocalMap = null;

        return parent::delete();
    }
}

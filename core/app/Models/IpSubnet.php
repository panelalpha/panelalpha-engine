<?php

namespace App\Models;

use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $id
 * @property string $ip
 * @property int $mask
 * @property int $family
 * @property bool $is_shared
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property Collection<IpAssigned> $ipAssigned
 * @method static ?IpSubnet find(int $id)
 * @method static IpSubnet findOrFail(int $id)
 * @method static IpSubnet create(array $params)
 * @method static Collection<IpSubnet> get()
 */
class IpSubnet extends Model
{
    protected $fillable = [
        'ip',
        'mask',
        'family',
        'is_shared',
    ];

    public function ipAssigned(): HasMany
    {
        return $this->hasMany(IpAssigned::class);
    }

    /**
     * @return array<string>
     */
    public function listAssignedIpAddresses(): array
    {
        /** @var array<string> */
        return $this->ipAssigned()->getQuery()->pluck('ip_address')->all();
    }

    public function getCidr(): string
    {
        return $this->ip . '/' . $this->mask;
    }

    /**
     *  @param array<string> $reserved
     */
    public function findFreeIp(array $reserved = []): ?string
    {
        $maskBits = $this->mask;
        $totalBits = $this->family === 6 ? 128 : 32;
        $hostBits = $totalBits - $maskBits;
        $maxHosts = BigInteger::one()->shiftedLeft($hostBits);

        $usedBig = [];
        foreach ($reserved as $ip) {
            // An unset default IP arrives as '', which inet_pton() cannot read.
            $packed = inet_pton($ip);
            if ($packed === false) {
                continue;
            }
            $usedBig[(string) BigInteger::fromBytes($packed, false)] = true;
        }
        foreach ($this->listAssignedIpAddresses() as $ip) {
            $usedBig[(string) BigInteger::fromBytes(inet_pton($ip), false)] = true;
        }

        $base = BigInteger::fromBytes(inet_pton($this->ip), false);

        // TODO support & optimize for big subnets
        $limit = $maxHosts->compareTo(65536) > 0 ? 65536 : $maxHosts->toInt();

        for ($i = 1; $i < $limit; $i++) {
            $candidate = $base->plus($i);
            if (!isset($usedBig[(string) $candidate])) {
                return inet_ntop($candidate->toBytes(false));
            }
        }

        return null;
    }
}

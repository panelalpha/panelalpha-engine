<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $ip_subnet_id
 * @property string $ip_address
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User $user
 *
 * @method static IpAssigned FindOrFail(int $id)
 * @method static IpAssigned create(array $params)
 * @method static Collection<IpAssigned> get()
 */
class IpAssigned extends Model
{
    protected $table = 'ip_assigned';

    protected $fillable = [
        'user_id',
        'ip_subnet_id',
        'ip_address',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ipSubnet(): BelongsTo
    {
        return $this->belongsTo(IpSubnet::class);
    }
}

<?php

namespace App\Models;

use App\Lib\Traits\Models\HasDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $username
 * @property string $auth_method
 * @property string $password
 * @property string $public_key
 * @property ?array $details
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User $userModel
 *
 * @method static SftpAccount create(array $params)
 */
class SftpAccount extends Model
{
    use HasDetails, HasFactory;

    protected $fillable = [
        'user_id',
        'username',
        'auth_method',
        'password',
        'public_key',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getAuthMethods(): array
    {
        return explode(',', $this->auth_method);
    }
}

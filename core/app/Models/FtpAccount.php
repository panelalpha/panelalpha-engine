<?php

namespace App\Models;

use App\Lib\Traits\Models\HasDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $user
 * @property string $directory
 * @property ?array $details
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User $userModel
 */
class FtpAccount extends Model
{
    use HasDetails, HasFactory;

    protected $fillable = [
        'user_id',
        'user',
        'directory',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function userModel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function getDiskUsage(): string
    {
        return $this->userModel->project()->ftp()->diskUsage($this->directory);
    }
}

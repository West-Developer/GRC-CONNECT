<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $secret_ciphertext
 * @property ?int $updated_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read ?User $updater
 */
final class QueueKioskCredential extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'secret_ciphertext',
        'updated_by',
    ];

    /** @var list<string> */
    protected $hidden = [
        'secret_ciphertext',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

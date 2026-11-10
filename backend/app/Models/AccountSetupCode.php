<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
final class AccountSetupCode extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'code_hash',
        'attempts',
        'expires_at',
        'created_at',
    ];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

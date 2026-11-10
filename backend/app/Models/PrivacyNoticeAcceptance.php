<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That one person agreed to one version of the Data Privacy Notice, and when (ADR 0055).
 *
 * @property int $id
 * @property int $user_id
 * @property string $notice_version
 * @property string $method
 * @property ?int $recorded_by_user_id
 * @property CarbonImmutable $accepted_at
 */
final class PrivacyNoticeAcceptance extends Model
{
    public const METHOD_SELF = 'self';

    public const METHOD_ASSISTED = 'assisted';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'notice_version',
        'method',
        'recorded_by_user_id',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'immutable_datetime',
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

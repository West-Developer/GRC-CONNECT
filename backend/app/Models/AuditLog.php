<?php

namespace App\Models;

use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $actor_user_id
 * @property ?string $acting_role
 * @property ?string $acting_college
 * @property string $action
 * @property string $auditable_type
 * @property ?int $auditable_id
 * @property ?array<string, mixed> $before_values
 * @property ?array<string, mixed> $after_values
 * @property ?string $reason
 * @property string $request_id
 * @property ?string $ip_address
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read User $actor
 */
final class AuditLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'actor_user_id',
        'acting_role',
        'acting_college',
        'action',
        'auditable_type',
        'auditable_id',
        'before_values',
        'after_values',
        'reason',
        'request_id',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Audit logs are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Audit logs are immutable.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * The role the actor was actually acting as when this entry was
     * recorded: the office a super admin had switched into via the
     * Department Switcher, or the actor's own stored role otherwise. Use
     * this instead of `$this->actor->role` anywhere a narrower-than-"every
     * role" contract reads it (e.g. `ScheduleProposalResource`'s
     * `returned_by_role` — ADR 0038).
     */
    public function effectiveActorRole(): UserRole
    {
        return $this->acting_role !== null
            ? UserRole::from($this->acting_role)
            : $this->actor->role;
    }
}

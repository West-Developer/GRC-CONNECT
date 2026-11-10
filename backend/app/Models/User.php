<?php

namespace App\Models;

use App\Domain\Identity\ActingContext;
use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\PersonName;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property ?string $first_name
 * @property ?string $middle_name
 * @property ?string $middle_initial
 * @property ?string $last_name
 * @property ?string $suffix
 * @property string $email
 * @property string $password
 * @property UserRole $role
 * @property ?CollegeCode $college
 * @property ?FacultyEmploymentType $employment_type
 * @property ?string $masters_degree
 * @property UserStatus $status
 * @property ?string $deactivation_reason
 * @property ?CarbonImmutable $last_login_at
 * @property ?CarbonImmutable $last_otp_verified_at
 * @property ?CarbonImmutable $account_setup_completed_at
 * @property ?CarbonImmutable $account_setup_invitation_sent_at
 * @property ?CarbonImmutable $account_setup_invitation_failed_at
 * @property ?CarbonImmutable $archived_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Collection<int, AuditLog> $auditLogs
 * @property-read Collection<int, Notification> $notifications
 * @property-read ?QueueKioskCredential $queueKioskCredential
 * @property-read ?StudentProfile $studentProfile
 */
final class User extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword;
    use HasApiTokens;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'first_name',
        'middle_name',
        'middle_initial',
        'last_name',
        'suffix',
        'email',
        'password',
        'role',
        'college',
        'employment_type',
        'masters_degree',
        'status',
        'deactivation_reason',
        'last_login_at',
        'last_otp_verified_at',
        'account_setup_completed_at',
        'account_setup_invitation_sent_at',
        'account_setup_invitation_failed_at',
        'archived_at',
    ];

    protected static function booted(): void
    {
        // A user written with only a display name (a seeder, a script, an import) gets its structured
        // parts derived, because the API contracts require first and last name. Parts that were
        // supplied are never touched, and `name` itself is never rewritten here. The column check
        // guards a handful of migration-reversibility tests that roll the `users` table back to
        // before the columns existed and still create a User against that older schema.
        self::saving(function (self $user): void {
            if (blank($user->first_name) && blank($user->last_name) && filled($user->name)
                && Schema::hasColumn($user->getTable(), 'first_name')) {
                $user->fill(PersonName::split((string) $user->name));
            }
        });
    }

    /** @var list<string> */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'college' => CollegeCode::class,
            'employment_type' => FacultyEmploymentType::class,
            'status' => UserStatus::class,
            'last_login_at' => 'immutable_datetime',
            'last_otp_verified_at' => 'immutable_datetime',
            'account_setup_completed_at' => 'immutable_datetime',
            'account_setup_invitation_sent_at' => 'immutable_datetime',
            'account_setup_invitation_failed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasOne<QueueKioskCredential, $this>
     */
    public function queueKioskCredential(): HasOne
    {
        return $this->hasOne(QueueKioskCredential::class);
    }

    /** @return HasOne<StudentProfile, $this> */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    private ?ActingContext $actingContext = null;

    public function isSuperAdmin(): bool
    {
        // reads the RAW stored value, bypassing any override below
        return $this->getRawOriginal('role') === UserRole::SuperAdmin->value;
    }

    public function actingContext(): ?ActingContext
    {
        return $this->actingContext;
    }

    public function applyActingContext(?ActingContext $context): void
    {
        $this->actingContext = $context;
    }

    public function getAttributeValue($key)
    {
        if ($this->actingContext !== null) {
            if ($key === 'role') {
                return $this->actingContext->role;
            }
            if ($key === 'college') {
                return $this->actingContext->college;
            }
        }

        return parent::getAttributeValue($key);
    }

    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        if ($this->actingContext !== null) {
            $attributes['role'] = $this->actingContext->role->value;
            $attributes['college'] = $this->actingContext->college?->value;
        }

        return $attributes;
    }
}

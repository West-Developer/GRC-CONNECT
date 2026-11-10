<?php

namespace App\Models;

use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $student_id
 * @property int $academic_term_id
 * @property EnrollmentStatus $status
 * @property float $total_units
 * @property bool $requires_overload_approval
 * @property ?float $unit_maximum
 * @property ?string $program_head_comment
 * @property ?CarbonImmutable $submitted_at
 * @property ?CarbonImmutable $program_head_decided_at
 * @property ?CarbonImmutable $registrar_decided_at
 * @property ?CarbonImmutable $payment_confirmed_at
 * @property ?CarbonImmutable $enrolled_at
 * @property ?int $active_academic_term_id
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read StudentProfile $student
 * @property-read AcademicTerm $academicTerm
 * @property-read Collection<int, EnrollmentSubject> $enrollmentSubjects
 * @property-read ?QueueTicket $queueTicket
 * @property-read ?Payment $payment
 * @property-read Collection<int, AccountPayment> $accountPayments
 * @property-read ?Assessment $assessment
 * @property-read Collection<int, EnrollmentDocument> $documents
 */
final class Enrollment extends Model
{
    /**
     * `active_academic_term_id` is deliberately absent: it is a STORED
     * generated column maintained by the database (see the enrollments
     * migration), and attempting to write it would raise a SQL error.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'academic_term_id',
        'status',
        'total_units',
        'requires_overload_approval',
        'unit_maximum',
        'program_head_comment',
        'submitted_at',
        'program_head_decided_at',
        'registrar_decided_at',
        'payment_confirmed_at',
        'enrolled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'total_units' => 'float',
            'requires_overload_approval' => 'boolean',
            'unit_maximum' => 'float',
            'submitted_at' => 'immutable_datetime',
            'program_head_decided_at' => 'immutable_datetime',
            'registrar_decided_at' => 'immutable_datetime',
            'payment_confirmed_at' => 'immutable_datetime',
            'enrolled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Enrollments that still hold the student's seat for their term. Mirrors
     * the database's generated-column definition; see EnrollmentStatus.
     *
     * @param  Builder<Enrollment>  $query
     * @return Builder<Enrollment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', EnrollmentStatus::terminalValues());
    }

    /**
     * PRD §5.3 FR-FIN-001/005: a Student sees only their own enrollments; the
     * Registrar Head and Registrar Staff each see every enrollment (Staff
     * works the approval queue, Head retains oversight and the `void`
     * checkpoint); Accounting Staff sees only `pending_payment` rows —
     * FR-FIN-005 is an authorization boundary, not a display filter, so it
     * belongs here (and is re-checked by `EnrollmentPolicy`), not only in a
     * frontend filter. `EnrollmentPolicy::viewAny()` already restricts every
     * other role to zero rows before this scope ever runs.
     *
     * @param  Builder<Enrollment>  $query
     * @return Builder<Enrollment>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::AccountingStaff) {
            return $query->where('status', EnrollmentStatus::PendingPayment->value);
        }

        if (
            $user->role === UserRole::RegistrarHead
            || $user->role === UserRole::RegistrarStaff
            || $user->role === UserRole::Dean
            || $user->role === UserRole::ExecutiveDirector
        ) {
            return $query;
        }

        if ($user->role === UserRole::ProgramChair) {
            if ($user->college !== null) {
                return $query->whereHas('student.program', fn ($programQuery) => $programQuery->where('college', $user->college->value));
            }

            return $query;
        }

        return $query->whereHas('student', fn ($studentQuery) => $studentQuery->where('user_id', $user->id));
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * @return HasMany<EnrollmentSubject, $this>
     */
    public function enrollmentSubjects(): HasMany
    {
        return $this->hasMany(EnrollmentSubject::class);
    }

    /**
     * The Program Chair's change proposals to this enrollment, oldest first
     * (ADR 0040).
     *
     * @return HasMany<EnrollmentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(EnrollmentRevision::class)->orderBy('id');
    }

    /**
     * @return HasOne<QueueTicket, $this>
     */
    public function queueTicket(): HasOne
    {
        return $this->hasOne(QueueTicket::class);
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * @return HasMany<AccountPayment, $this>
     */
    public function accountPayments(): HasMany
    {
        return $this->hasMany(AccountPayment::class);
    }

    /**
     * @return HasOne<Assessment, $this>
     */
    public function assessment(): HasOne
    {
        return $this->hasOne(Assessment::class);
    }

    /**
     * @return HasMany<EnrollmentDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EnrollmentDocument::class);
    }

    public function isLateEnrollee(): bool
    {
        $submittedAt = $this->submitted_at ?? $this->created_at;
        if (! $submittedAt) {
            return false;
        }

        $term = $this->academicTerm;
        if (! $term) {
            return false;
        }

        /** @var ?AcademicTermEnrollmentWindow $lateWindow */
        $lateWindow = $term->enrollmentWindows?->first(
            fn (AcademicTermEnrollmentWindow $w) => $w->audience === EnrollmentAudience::LateEnrollee
        );

        if ($lateWindow && $lateWindow->opens_at) {
            return $submittedAt->greaterThanOrEqualTo($lateWindow->opens_at);
        }

        return false;
    }
}

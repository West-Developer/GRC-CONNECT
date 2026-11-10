<?php

namespace App\Models;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $student_id
 * @property string $source_institution
 * @property string $source_subject_code
 * @property string $source_subject_title
 * @property ?string $source_grade
 * @property float $credited_units
 * @property ?string $source_school_year
 * @property ?string $source_semester
 * @property ?int $subject_id
 * @property ?int $requested_by
 * @property ?int $endorsed_by
 * @property ?CarbonImmutable $endorsed_at
 * @property ?CarbonImmutable $submitted_at
 * @property ?int $returned_by
 * @property ?CarbonImmutable $returned_at
 * @property ?string $return_reason
 * @property TransfereeCreditStatus $status
 * @property ?int $processed_by
 * @property ?CarbonImmutable $processed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read StudentProfile $student
 * @property-read ?Subject $subject
 * @property-read ?User $processor
 * @property-read ?User $requester
 * @property-read ?User $endorser
 * @property-read ?User $returner
 */
final class TransfereeCredit extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'source_institution',
        'source_subject_code',
        'source_subject_title',
        'source_grade',
        'credited_units',
        'source_school_year',
        'source_semester',
        'subject_id',
        'requested_by',
        'endorsed_by',
        'endorsed_at',
        'submitted_at',
        'returned_by',
        'returned_at',
        'return_reason',
        'status',
        'processed_by',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TransfereeCreditStatus::class,
            'credited_units' => 'float',
            'endorsed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endorser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Who may see which credits (ADR 0026, amended): the Registrar Head decides
     * credits and Registrar Staff follow them, both reading every credit; a
     * Program Chair reads their own college's students' credits; a Student
     * reads their own.
     *
     * A `draft` is the student's own saved list, not yet sent, and is private to
     * them: no other role sees one, however it asks.
     *
     * An `approved` credit that is mapped to a subject counts as credited for
     * eligibility, standing, promotion and the prospectus (see
     * `App\Actions\Academic\ResolveCreditedSubjectIds`); it carries no GRC
     * grade, so PRD §17's cross-institution grade equivalence stays open and
     * nothing here feeds a GWA.
     *
     * @param  Builder<TransfereeCredit>  $query
     * @return Builder<TransfereeCredit>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $notDraft = TransfereeCreditStatus::Draft->value;

        if (in_array($user->role, [UserRole::RegistrarStaff, UserRole::RegistrarHead], true)) {
            return $query->where('status', '!=', $notDraft);
        }

        if ($user->role === UserRole::ProgramChair) {
            $query->where('status', '!=', $notDraft);

            if ($user->college !== null) {
                return $query->whereHas(
                    'student.program',
                    fn ($programQuery) => $programQuery->where('college', $user->college->value),
                );
            }

            return $query;
        }

        return $query->whereHas(
            'student',
            fn ($studentQuery) => $studentQuery->where('user_id', $user->id),
        );
    }
}

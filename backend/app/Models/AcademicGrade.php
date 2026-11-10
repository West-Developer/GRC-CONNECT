<?php

namespace App\Models;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $student_id
 * @property int $subject_id
 * @property ?int $section_id
 * @property int $academic_term_id
 * @property ?string $final_grade
 * @property ?GradeMark $mark
 * @property ?string $remarks
 * @property GradeStatus $status
 * @property int $encoded_by
 * @property ?CarbonImmutable $submitted_at
 * @property ?CarbonImmutable $locked_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read StudentProfile $student
 * @property-read Subject $subject
 * @property-read ?Section $section
 * @property-read AcademicTerm $academicTerm
 * @property-read User $encoder
 */
final class AcademicGrade extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'subject_id',
        'section_id',
        'academic_term_id',
        'final_grade',
        'mark',
        'remarks',
        'status',
        'encoded_by',
        'submitted_at',
        'locked_at',
    ];

    /**
     * `final_grade` is intentionally NOT cast to float. The official grading
     * scale, special marks, and equivalent grades are open PRD §17 decisions,
     * so the value is carried as the exact decimal string MySQL returns rather
     * than being coerced into a numeric type this system would then be
     * implicitly interpreting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GradeStatus::class,
            'mark' => GradeMark::class,
            'submitted_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
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
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }

    /**
     * PRD §4.3 / §5.3 DFD 3.1: a Student sees only their own grade records;
     * Faculty sees only grades tied to a section they are assigned to teach
     * (a grade with no `section_id` is therefore invisible to every
     * Faculty member, which is correct — it belongs to no one's roster);
     * the Registrar Head, as keeper of the official academic record, sees
     * every grade. Registrar Staff gets the same broad read access per
     * PRD §3.8 ("view permitted academic records") — see Phase 7b Task 3.
     * Mirrors `Enrollment::scopeVisibleTo` (ADR 0008).
     *
     * @param  Builder<AcademicGrade>  $query
     * @return Builder<AcademicGrade>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Faculty) {
            return $query->whereHas('section', fn ($sectionQuery) => $sectionQuery->where('professor_id', $user->id));
        }

        if (in_array($user->role, [UserRole::RegistrarHead, UserRole::RegistrarStaff], true)) {
            return $query;
        }

        return $query->whereHas('student', fn ($studentQuery) => $studentQuery->where('user_id', $user->id));
    }
}

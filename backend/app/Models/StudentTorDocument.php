<?php

namespace App\Models;

use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded Transcript of Records file. The file is on the private `local`
 * disk at `stored_path`; this row is its metadata.
 *
 * @property int $id
 * @property int $student_id
 * @property ?int $uploaded_by
 * @property string $original_name
 * @property string $stored_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read StudentProfile $student
 */
final class StudentTorDocument extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'uploaded_by',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * Which TORs a user may see: a Student their own, a Program Chair their
     * college's students (or all, when the chair has no college — the same
     * fallback `TransfereeCredit::scopeVisibleTo` uses), Registrar Staff and
     * Registrar Head all.
     *
     * @param  Builder<StudentTorDocument>  $query
     * @return Builder<StudentTorDocument>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (in_array($user->role, [UserRole::RegistrarStaff, UserRole::RegistrarHead], true)) {
            return $query;
        }

        if ($user->role === UserRole::ProgramChair) {
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

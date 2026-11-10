<?php

namespace App\Models;

use App\Casts\EnrollmentDocumentTypeCast;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $enrollment_id
 * @property EnrollmentDocumentType $document_type
 * @property string $document_number
 * @property ?string $storage_path
 * @property ?string $content_hash
 * @property ?array<string, mixed> $snapshot
 * @property CarbonImmutable $generated_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Enrollment $enrollment
 */
final class EnrollmentDocument extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'document_type',
        'document_number',
        'storage_path',
        'content_hash',
        'snapshot',
        'generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => EnrollmentDocumentTypeCast::class,
            'snapshot' => 'array',
            'generated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** The UI never exposes a legacy COM prefix after the artifact rename. */
    public function certificateNumber(): string
    {
        return preg_replace('/^COM/', 'COR', $this->document_number) ?? $this->document_number;
    }

    /**
     * A Student sees only their own CORs. Accounting Staff, Registrar Head,
     * and Registrar Staff may read the official COR history.
     *
     * @param  Builder<EnrollmentDocument>  $query
     * @return Builder<EnrollmentDocument>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (in_array($user->role, [
            UserRole::AccountingStaff,
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
        ], true)) {
            return $query;
        }

        return $query->whereHas('enrollment.student', fn ($studentQuery) => $studentQuery->where('user_id', $user->id));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reusable declared or workbook-seeded subject preference for a curriculum slot. */
final class FacultyCurriculumSubjectPreference extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'professor_id',
        'curriculum_id',
        'subject_id',
        'semester',
        'rank',
        'origin',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rank' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_id');
    }

    /** @return BelongsTo<Curriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}

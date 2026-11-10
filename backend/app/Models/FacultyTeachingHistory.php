<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Source-provenance teaching evidence used only as advisory ranking input. */
final class FacultyTeachingHistory extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'professor_id',
        'curriculum_id',
        'subject_id',
        'semester',
        'source_kind',
        'source_workbook',
        'raw_alias',
        'evidence_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence_count' => 'integer'];
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

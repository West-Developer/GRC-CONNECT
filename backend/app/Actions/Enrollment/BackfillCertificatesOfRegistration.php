<?php

namespace App\Actions\Enrollment;

use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class BackfillCertificatesOfRegistration
{
    public function __construct(private BuildCorSnapshot $buildCorSnapshot) {}

    public function execute(?int $enrollmentId = null): int
    {
        $ids = Enrollment::query()
            ->where('status', EnrollmentStatus::Enrolled)
            ->when($enrollmentId !== null, fn ($query) => $query->whereKey($enrollmentId))
            ->pluck('id');
        $supportsSnapshots = Schema::hasColumn('enrollment_documents', 'snapshot');

        $changed = 0;
        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id, $supportsSnapshots): int {
                $enrollment = Enrollment::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $payment = $enrollment->payment()->with('confirmer')->first();
                $document = $enrollment->documents()->first();
                if ($supportsSnapshots && $document?->snapshot !== null) {
                    return 0;
                }

                // The local production-like database may still be awaiting
                // the reversible snapshot migration. Its old document-type
                // column accepts `com` but not `cor`; write a compatibility
                // row that the cast presents as COR immediately. Once the
                // migration is applied, this same record receives its durable
                // immutable snapshot through the branch below.
                if (! $supportsSnapshots) {
                    if ($document !== null) {
                        return 0;
                    }

                    DB::table('enrollment_documents')->insert([
                        'enrollment_id' => $enrollment->id,
                        'document_type' => 'com',
                        'document_number' => sprintf('COM%06d', $enrollment->id),
                        'storage_path' => null,
                        'content_hash' => null,
                        'generated_at' => $enrollment->enrolled_at ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return 1;
                }

                $snapshot = $this->buildCorSnapshot->execute(
                    $enrollment->fresh([
                        'student.user',
                        'student.program',
                        'academicTerm',
                        'enrollmentSubjects.section.subject',
                        'assessment.items',
                    ]),
                    $payment,
                );

                if ($document === null) {
                    EnrollmentDocument::create([
                        'enrollment_id' => $enrollment->id,
                        'document_type' => EnrollmentDocumentType::Cor,
                        'document_number' => sprintf('COR%06d', $enrollment->id),
                        'snapshot' => $snapshot,
                        'content_hash' => $this->buildCorSnapshot->hash($snapshot),
                        'generated_at' => $payment?->confirmed_at ?? $enrollment->enrolled_at ?? now(),
                    ]);
                } else {
                    $document->update([
                        'snapshot' => $snapshot,
                        'content_hash' => $this->buildCorSnapshot->hash($snapshot),
                    ]);
                }

                return 1;
            });
        }

        return $changed;
    }
}

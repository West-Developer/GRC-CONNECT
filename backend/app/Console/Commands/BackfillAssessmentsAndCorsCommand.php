<?php

namespace App\Console\Commands;

use App\Actions\Billing\AssessEnrollment;
use App\Actions\Enrollment\BuildCorSnapshot;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillAssessmentsAndCorsCommand extends Command
{
    protected $signature = 'billing:backfill-cors';
    protected $description = 'Backfills fee assessments and regenerates COR snapshots with full tuition and other fees';

    public function handle(AssessEnrollment $assessor, BuildCorSnapshot $corBuilder): int
    {
        $this->info('Starting assessment and COR backfill...');

        $query = Enrollment::query()
            ->whereIn('status', [EnrollmentStatus::Enrolled, EnrollmentStatus::PendingPayment]);

        $total = $query->count();
        $this->info("Found {$total} enrollments to process.");

        $processed = 0;
        $updatedAssessments = 0;
        $updatedCors = 0;

        $query->with([
            'student.user',
            'student.program',
            'academicTerm',
            'enrollmentSubjects.section.subject',
            'assessment.items',
            'payment.confirmer',
            'documents',
        ])->chunkById(100, function ($enrollments) use ($assessor, $corBuilder, &$processed, &$updatedAssessments, &$updatedCors, $total): void {
            foreach ($enrollments as $enrollment) {
                DB::transaction(function () use ($enrollment, $assessor, $corBuilder, &$updatedAssessments, &$updatedCors): void {
                    // Check if assessment needs to be created or recomputed
                    if ($enrollment->assessment === null || (float) $enrollment->assessment->total_amount == 0) {
                        if ($enrollment->assessment !== null) {
                            $enrollment->assessment->items()->delete();
                            $enrollment->assessment->delete();
                        }
                        $assessment = $assessor->execute($enrollment);
                        $enrollment->setRelation('assessment', $assessment);
                        $updatedAssessments++;
                    }

                    $payment = $enrollment->payment;
                    $snapshot = $corBuilder->execute($enrollment->fresh([
                        'student.user',
                        'student.program',
                        'academicTerm',
                        'enrollmentSubjects.section.subject',
                        'assessment.items',
                    ]), $payment);

                    $document = $enrollment->documents->first();
                    if ($document === null) {
                        EnrollmentDocument::create([
                            'enrollment_id' => $enrollment->id,
                            'document_type' => EnrollmentDocumentType::Cor,
                            'document_number' => sprintf('COR%06d', $enrollment->id),
                            'snapshot' => $snapshot,
                            'content_hash' => $corBuilder->hash($snapshot),
                            'generated_at' => $payment?->confirmed_at ?? $enrollment->enrolled_at ?? now(),
                        ]);
                        $updatedCors++;
                    } else {
                        $document->update([
                            'snapshot' => $snapshot,
                            'content_hash' => $corBuilder->hash($snapshot),
                        ]);
                        $updatedCors++;
                    }
                });

                $processed++;
            }

            $this->output->write("\rProcessed {$processed}/{$total} enrollments...");
        });

        $this->newLine();
        $this->info("Complete! {$updatedAssessments} assessments created/updated, {$updatedCors} COR snapshots synchronized.");

        return self::SUCCESS;
    }
}

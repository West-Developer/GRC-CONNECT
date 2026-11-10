<?php

namespace Tests\Unit\Models;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Models\TransfereeCredit;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class TransfereeCreditTest extends TestCase
{
    public function test_status_and_credited_units_use_their_canonical_casts(): void
    {
        $credit = new TransfereeCredit;
        $credit->forceFill([
            'student_id' => 1,
            'source_institution' => 'Synthetic Partner College',
            'source_subject_code' => 'ITE-101',
            'source_subject_title' => 'Introduction to Information Technology',
            'credited_units' => '1.5',
            'status' => 'approved',
            'processed_at' => '2026-08-05 09:00:00',
        ]);

        self::assertSame(TransfereeCreditStatus::Approved, $credit->status);
        // Fractional units are allowed (decimal(4,1)), so the cast is a float.
        self::assertSame(1.5, $credit->credited_units);
        self::assertInstanceOf(CarbonImmutable::class, $credit->processed_at);
    }

    public function test_submission_and_return_columns_are_fillable_and_cast(): void
    {
        $credit = new TransfereeCredit;
        $credit->fill([
            'status' => 'draft',
            'submitted_at' => '2026-10-04 09:00:00',
            'returned_by' => 7,
            'returned_at' => '2026-10-05 10:30:00',
            'return_reason' => 'Mapped to the wrong subject.',
        ]);

        self::assertSame(TransfereeCreditStatus::Draft, $credit->status);
        self::assertInstanceOf(CarbonImmutable::class, $credit->submitted_at);
        self::assertInstanceOf(CarbonImmutable::class, $credit->returned_at);
        self::assertSame(7, $credit->returned_by);
        self::assertSame('Mapped to the wrong subject.', $credit->return_reason);
    }
}

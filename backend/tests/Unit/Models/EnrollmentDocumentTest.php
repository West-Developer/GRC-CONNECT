<?php

namespace Tests\Unit\Models;

use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Models\EnrollmentDocument;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class EnrollmentDocumentTest extends TestCase
{
    public function test_document_type_snapshot_and_generated_at_use_their_canonical_casts(): void
    {
        $document = new EnrollmentDocument;
        $document->setRawAttributes([
            'enrollment_id' => 1,
            'document_type' => 'cor',
            'document_number' => 'COR-2026-000001',
            'snapshot' => '{"document_title":"Certificate of Registration"}',
            'generated_at' => '2026-08-05 09:00:00',
        ]);

        self::assertSame(EnrollmentDocumentType::Cor, $document->document_type);
        self::assertSame(
            ['document_title' => 'Certificate of Registration'],
            $document->snapshot,
        );
        self::assertInstanceOf(CarbonImmutable::class, $document->generated_at);
    }

    public function test_a_legacy_com_row_reads_as_the_single_canonical_cor_type_until_migrated(): void
    {
        $document = new EnrollmentDocument;
        $document->setRawAttributes([
            'enrollment_id' => 1,
            'document_type' => 'com',
            'document_number' => 'COM-2026-000001',
            'generated_at' => '2026-08-05 09:00:00',
        ]);

        self::assertSame(EnrollmentDocumentType::Cor, $document->document_type);
    }
}

<?php

namespace Tests\Unit\Models;

use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AcademicTermTest extends TestCase
{
    public function test_status_attribute_uses_the_canonical_enum_cast(): void
    {
        $term = new AcademicTerm;
        $term->forceFill([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => 'draft',
        ]);

        self::assertSame(AcademicTermStatus::Draft, $term->status);
    }

    public function test_datetime_fields_are_cast_to_carbon_immutable(): void
    {
        $term = new AcademicTerm;
        $term->forceFill([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => 'draft',
            'starts_at' => '2026-08-01 00:00:00',
        ]);

        self::assertInstanceOf(CarbonImmutable::class, $term->starts_at);
    }

    public function test_deadline_fields_are_cast_to_carbon_immutable(): void
    {
        $term = new AcademicTerm;
        $term->forceFill([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => 'draft',
            'add_drop_deadline_at' => '2026-08-15 00:00:00',
            'grading_deadline_at' => '2026-12-15 00:00:00',
        ]);

        self::assertInstanceOf(CarbonImmutable::class, $term->add_drop_deadline_at);
        self::assertInstanceOf(CarbonImmutable::class, $term->grading_deadline_at);
    }

    public function test_archive_metadata_is_cast_to_carbon_immutable(): void
    {
        $term = new AcademicTerm;
        $term->forceFill([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => 'semester_closed',
            'closed_at' => '2026-08-01 00:00:00',
            'archived_at' => '2026-08-02 00:00:00',
        ]);

        self::assertInstanceOf(CarbonImmutable::class, $term->closed_at);
        self::assertInstanceOf(CarbonImmutable::class, $term->archived_at);
    }

    public function test_only_actionable_term_statuses_are_current(): void
    {
        foreach ([
            'draft' => true,
            'for_dean_approval' => true,
            'semester_ongoing' => true,
            'semester_closed' => false,
            'archived' => false,
        ] as $status => $expected) {
            $term = new AcademicTerm;
            $term->forceFill(['status' => $status]);

            self::assertSame($expected, $term->isActionableCurrent(), $status);
        }
    }
}

<?php

namespace Tests\Unit\Domain\Organization;

use App\Domain\Organization\WorkbookFacultyProfileName;
use PHPUnit\Framework\TestCase;

final class WorkbookFacultyProfileNameTest extends TestCase
{
    public function test_it_prefers_the_complete_workbook_alias_for_a_profile_name(): void
    {
        self::assertSame(
            'Henry N. Corrales',
            WorkbookFacultyProfileName::displayName(['MR. H. CORRALES', 'Henry N. Corrales']),
        );
    }

    public function test_it_generates_a_safe_full_name_for_a_surname_only_alias(): void
    {
        $name = WorkbookFacultyProfileName::displayName(['MR. CACHO']);

        self::assertMatchesRegularExpression('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $name);
        self::assertStringNotContainsStringIgnoringCase('MR', $name);
    }

    public function test_it_keeps_surname_only_aliases_separate_from_named_people_with_the_same_surname(): void
    {
        self::assertNotSame(
            WorkbookFacultyProfileName::identityKey('BARBIN'),
            WorkbookFacultyProfileName::identityKey('Rafael Barbin'),
        );
    }
}

<?php

namespace Tests\Unit\Domain\Organization;

use App\Domain\Organization\CatalogSectionMetadata;
use PHPUnit\Framework\TestCase;

final class CatalogSectionMetadataTest extends TestCase
{
    public function test_it_reads_the_year_from_catalog_section_labels(): void
    {
        self::assertSame(3, CatalogSectionMetadata::yearLevel('IT 301|IT 302|IT 303'));
        self::assertSame(1, CatalogSectionMetadata::yearLevel('FM 102|FM 202'));
        self::assertSame(1, CatalogSectionMetadata::yearLevel('ELEM101'));
    }

    public function test_it_preserves_single_and_multi_semester_offerings(): void
    {
        self::assertSame('1st', CatalogSectionMetadata::semester('1st Semester'));
        self::assertSame('2nd', CatalogSectionMetadata::semester('2nd Semester'));
        self::assertSame('1st|2nd', CatalogSectionMetadata::semester('1st Semester|2nd Semester'));
    }
}

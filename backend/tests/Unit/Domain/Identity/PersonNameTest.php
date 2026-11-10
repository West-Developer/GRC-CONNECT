<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\PersonName;
use Tests\TestCase;

final class PersonNameTest extends TestCase
{
    public function test_composes_first_and_last_only(): void
    {
        self::assertSame('Aurora Lopez', PersonName::compose('Aurora', null, 'Lopez', null));
    }

    public function test_composes_with_a_middle_initial(): void
    {
        self::assertSame('Aurora S. Lopez', PersonName::compose('Aurora', 'S', 'Lopez', null));
    }

    public function test_does_not_double_the_period_on_an_already_punctuated_initial(): void
    {
        self::assertSame('Aurora S. Lopez', PersonName::compose('Aurora', 'S.', 'Lopez', null));
    }

    public function test_composes_with_a_suffix(): void
    {
        self::assertSame('Juan Dela Cruz Jr.', PersonName::compose('Juan', null, 'Dela Cruz', 'Jr.'));
    }

    public function test_composes_with_every_part(): void
    {
        self::assertSame('Juan M. Dela Cruz III', PersonName::compose('Juan', 'M', 'Dela Cruz', 'III'));
    }

    public function test_trims_and_ignores_blank_optional_parts(): void
    {
        self::assertSame('Aurora Lopez', PersonName::compose(' Aurora ', '  ', ' Lopez ', ''));
    }

    public function test_normalize_name_part_title_cases_an_all_lowercase_value(): void
    {
        self::assertSame('Aurora', PersonName::normalizeNamePart('aurora'));
    }

    public function test_normalize_name_part_title_cases_an_all_caps_value(): void
    {
        self::assertSame('Lopez', PersonName::normalizeNamePart('LOPEZ'));
    }

    public function test_normalize_name_part_title_cases_each_word_of_a_multi_word_value(): void
    {
        self::assertSame('Dela Cruz', PersonName::normalizeNamePart('dela cruz'));
    }

    public function test_normalize_name_part_title_cases_each_side_of_a_hyphen(): void
    {
        self::assertSame('Mary-Jane', PersonName::normalizeNamePart('mary-jane'));
    }

    public function test_normalize_name_part_title_cases_after_an_apostrophe(): void
    {
        self::assertSame("O'Brien", PersonName::normalizeNamePart("o'brien"));
    }

    public function test_normalize_name_part_trims_surrounding_whitespace(): void
    {
        self::assertSame('Aurora', PersonName::normalizeNamePart('  aurora  '));
    }

    public function test_normalize_name_part_preserves_null(): void
    {
        self::assertNull(PersonName::normalizeNamePart(null));
    }

    public function test_normalize_name_part_preserves_blank_string(): void
    {
        self::assertSame('', PersonName::normalizeNamePart('   '));
    }

    public function test_normalize_suffix_title_cases_jr(): void
    {
        self::assertSame('Jr.', PersonName::normalizeSuffix('jr.'));
    }

    public function test_normalize_suffix_title_cases_sr_without_a_period(): void
    {
        self::assertSame('Sr', PersonName::normalizeSuffix('SR'));
    }

    public function test_normalize_suffix_uppercases_a_roman_numeral(): void
    {
        self::assertSame('III', PersonName::normalizeSuffix('iii'));
    }

    public function test_normalize_suffix_uppercases_a_mixed_case_roman_numeral(): void
    {
        self::assertSame('IV', PersonName::normalizeSuffix('Iv'));
    }

    public function test_normalize_suffix_preserves_null(): void
    {
        self::assertNull(PersonName::normalizeSuffix(null));
    }

    public function test_split_reads_first_middle_last_and_suffix_from_a_display_name(): void
    {
        self::assertSame(
            ['first_name' => 'Aurora', 'middle_initial' => 'S', 'last_name' => 'Lopez', 'suffix' => 'Jr.'],
            PersonName::split('Aurora Santos Lopez Jr.'),
        );
    }

    public function test_split_treats_a_single_word_as_first_and_last(): void
    {
        self::assertSame(
            ['first_name' => 'Madonna', 'middle_initial' => null, 'last_name' => 'Madonna', 'suffix' => null],
            PersonName::split('Madonna'),
        );
    }

    public function test_split_reads_a_surname_comma_given_name_import_value(): void
    {
        self::assertSame(
            ['first_name' => 'JUAN', 'middle_initial' => null, 'last_name' => 'DELA CRUZ', 'suffix' => null],
            PersonName::split('DELA CRUZ,JUAN(MS)'),
        );
    }

    public function test_a_full_middle_name_is_shown_as_its_first_letter(): void
    {
        self::assertSame('R', PersonName::initial('Ramga'));
        self::assertSame('D', PersonName::initial('dela Cruz'));
        self::assertSame('Ñ', PersonName::initial('ñoño'));
        self::assertNull(PersonName::initial(null));
        self::assertNull(PersonName::initial('  '));
    }

    public function test_a_new_middle_name_replaces_what_is_stored_and_derives_the_initial(): void
    {
        self::assertSame(
            ['middle_name' => 'Ramga', 'middle_initial' => 'R'],
            PersonName::middle('ramga', null, null),
        );
        self::assertSame(
            ['middle_name' => 'Dela Cruz', 'middle_initial' => 'D'],
            PersonName::middle('DELA CRUZ', 'Ramga', 'R'),
        );
    }

    public function test_an_empty_middle_name_clears_both(): void
    {
        self::assertSame(['middle_name' => null, 'middle_initial' => null], PersonName::middle('  ', 'Ramga', 'R'));
        self::assertSame(['middle_name' => null, 'middle_initial' => null], PersonName::middle(null, null, 'S'));
    }

    public function test_a_middle_name_sent_back_unchanged_leaves_what_is_stored_alone(): void
    {
        // The same name, however it is typed.
        self::assertSame(['middle_name' => 'Ramga', 'middle_initial' => 'R'], PersonName::middle('RAMGA', 'Ramga', 'R'));
        // An older account that only has an initial: the form shows the initial, and sending it back is no change.
        self::assertSame(['middle_name' => null, 'middle_initial' => 'S'], PersonName::middle('S', null, 'S'));
        self::assertSame(['middle_name' => null, 'middle_initial' => 'S'], PersonName::middle('s.', null, 'S'));
        // A bare initial that matches the initial of the stored full name is no change either (an older client).
        self::assertSame(['middle_name' => 'Ramga', 'middle_initial' => 'R'], PersonName::middle('R', 'Ramga', 'R'));
    }

    public function test_the_display_name_uses_the_initial_of_a_full_middle_name(): void
    {
        $middle = PersonName::middle('Ramga', null, null);

        self::assertSame('West R. Ragma', PersonName::compose('West', $middle['middle_initial'], 'Ragma', null));
    }
}

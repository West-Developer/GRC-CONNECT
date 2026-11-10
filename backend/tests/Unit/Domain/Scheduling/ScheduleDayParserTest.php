<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\ScheduleDayParser;
use PHPUnit\Framework\TestCase;

final class ScheduleDayParserTest extends TestCase
{
    private ScheduleDayParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ScheduleDayParser;
    }

    public function test_an_empty_string_parses_to_no_days(): void
    {
        self::assertSame([], $this->parser->parse(''));
    }

    public function test_mwf_parses_to_monday_wednesday_friday(): void
    {
        self::assertSame([1, 3, 5], $this->parser->parse('MWF'));
    }

    public function test_tth_parses_to_tuesday_thursday_not_tuesday_tuesday_h(): void
    {
        self::assertSame([2, 4], $this->parser->parse('TTh'));
    }

    public function test_sat_parses_to_saturday(): void
    {
        self::assertSame([6], $this->parser->parse('Sat'));
    }

    public function test_uppercase_source_day_tokens_parse_case_insensitively(): void
    {
        self::assertSame([6], $this->parser->parse('SAT'));
    }

    public function test_sun_parses_to_sunday(): void
    {
        self::assertSame([7], $this->parser->parse('Sun'));
    }

    public function test_a_single_letter_day_parses_correctly(): void
    {
        self::assertSame([2], $this->parser->parse('T'));
        self::assertSame([4], $this->parser->parse('Th'));
    }

    public function test_an_unrecognized_trailing_token_stops_parsing_rather_than_guessing(): void
    {
        self::assertSame([1, 3], $this->parser->parse('MW?'));
    }

    public function test_duplicate_tokens_are_not_repeated_in_the_result(): void
    {
        self::assertSame([1], $this->parser->parse('MM'));
    }
}

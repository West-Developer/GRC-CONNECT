<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\FirstNameGenderGuesser;
use App\Domain\Identity\Gender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FirstNameGenderGuesserTest extends TestCase
{
    /**
     * @return array<string, array{string, Gender}>
     */
    public static function knownNames(): array
    {
        return [
            'male given name' => ['Jose', Gender::Male],
            'female given name' => ['Maria', Gender::Female],
            'case is ignored' => ['JUAN', Gender::Male],
            'first of two male names' => ['Juan Carlos', Gender::Male],
            'first of two female names' => ['Mary Joy', Gender::Female],
            'Ma. prefix is Maria' => ['Ma. Cristina', Gender::Female],
            'Ma without a dot' => ['Ma Theresa', Gender::Female],
            'female name listed in the roster pool' => ['Angel', Gender::Female],
            'male English-style name' => ['Christopher', Gender::Male],
            'female English-style name' => ['Jennifer', Gender::Female],
            'accents and punctuation are ignored' => ['Zenaida-', Gender::Female],
            'a nickname-first double name uses the known token' => ['Jhon Rey', Gender::Male],
        ];
    }

    #[DataProvider('knownNames')]
    public function test_it_recognises_known_given_names(string $name, Gender $expected): void
    {
        $this->assertSame($expected, (new FirstNameGenderGuesser)->guess($name));
    }

    public function test_it_does_not_guess_a_name_it_does_not_know(): void
    {
        $this->assertNull((new FirstNameGenderGuesser)->guess('Xqzvt'));
        $this->assertNull((new FirstNameGenderGuesser)->guess(''));
        $this->assertNull((new FirstNameGenderGuesser)->guess(null));
    }

    public function test_the_ending_rule_is_a_separate_weaker_guess(): void
    {
        $guesser = new FirstNameGenderGuesser;

        $this->assertSame(Gender::Female, $guesser->guessFromEnding('Marianela'));
        $this->assertSame(Gender::Female, $guesser->guessFromEnding('Rosalyn'));
        $this->assertSame(Gender::Male, $guesser->guessFromEnding('Ronaldo'));
        $this->assertNull($guesser->guessFromEnding('Xqzvt'));
        $this->assertNull($guesser->guessFromEnding(null));
    }
}

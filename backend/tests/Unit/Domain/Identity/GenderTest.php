<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Gender;
use PHPUnit\Framework\TestCase;

final class GenderTest extends TestCase
{
    public function test_it_is_stored_as_one_letter(): void
    {
        $this->assertSame('M', Gender::Male->value);
        $this->assertSame('F', Gender::Female->value);
    }

    public function test_it_gives_the_two_spellings_the_forms_ask_for(): void
    {
        $this->assertSame('M', Gender::Male->letter());
        $this->assertSame('F', Gender::Female->letter());
        $this->assertSame('MALE', Gender::Male->word());
        $this->assertSame('FEMALE', Gender::Female->word());
    }
}

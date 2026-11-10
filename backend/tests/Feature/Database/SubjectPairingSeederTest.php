<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\Subject;
use Database\Seeders\SubjectPairingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubjectPairingSeederTest extends TestCase
{
    use RefreshDatabase;

    private function subject(string $code, string $title, CollegeCode $college = CollegeCode::Ccs): Subject
    {
        return Subject::create(['college' => $college, 'code' => $code, 'title' => $title, 'units' => 3, 'status' => SubjectStatus::Active]);
    }

    public function test_it_links_a_lecture_to_its_laboratory_both_ways_and_is_idempotent(): void
    {
        $lecture = $this->subject('HCI', 'Intro to Human Computer Interaction LEC');
        $laboratory = $this->subject('HCIL', 'Intro to Human Computer Interaction LAB');

        $this->seed(SubjectPairingSeeder::class);
        $this->seed(SubjectPairingSeeder::class);

        $this->assertSame($laboratory->id, $lecture->fresh()->paired_subject_id);
        $this->assertSame($lecture->id, $laboratory->fresh()->paired_subject_id);
    }

    public function test_it_leaves_lookalike_codes_and_other_colleges_unpaired(): void
    {
        $standalone = $this->subject('KOMFIL', 'Komunikasyon sa Akademikong Filipino');
        $endsInL = $this->subject('RIZAL', 'Rizal Life and Works');
        $lecture = $this->subject('PROG1', 'Computer Programming 1 LEC');
        $otherCollegeLab = $this->subject('PROG1L', 'Computer Programming 1 LAB', CollegeCode::Coe);
        $labOnly = $this->subject('ITPLUS3', 'Office Productivity Tools LAB');

        $this->seed(SubjectPairingSeeder::class);

        foreach ([$standalone, $endsInL, $lecture, $otherCollegeLab, $labOnly] as $subject) {
            $this->assertNull($subject->fresh()->paired_subject_id, "{$subject->code} must stay unpaired.");
        }
    }
}

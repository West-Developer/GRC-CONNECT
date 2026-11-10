<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Actions\Enrollment\BuildCorSnapshot;
use App\Domain\Billing\AssessmentItemCategory;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Tests\TestCase;

final class BuildCorSnapshotTest extends TestCase
{
    public function test_it_builds_a_stable_printable_snapshot_from_confirmed_enrollment_data(): void
    {
        $studentUser = new User(['name' => 'Student One']);
        $program = new Program(['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology']);
        $student = new StudentProfile([
            'student_number' => '2026-0001',
            'year_level' => 4,
            'address' => "123 Mabini Street\nCaloocan City",
        ]);
        $student->setRelation('user', $studentUser);
        $student->setRelation('program', $program);

        $term = new AcademicTerm(['school_year' => '2026-2027', 'semester' => '1st']);
        $subject = new Subject(['code' => 'CS101', 'title' => 'Programming 1', 'units' => 3]);
        $section = new Section([
            'id' => 9,
            'section_code' => 'IV-BLOCK',
            'schedule_days' => 'MON',
            'starts_at_time' => '10:30:00',
            'ends_at_time' => '13:30:00',
            'room' => 'Hybrid Flexible Learning',
        ]);
        $section->setRelation('subject', $subject);
        $enrollmentSubject = new EnrollmentSubject(['section_id' => 9, 'status' => EnrollmentSubjectStatus::Enrolled]);
        $enrollmentSubject->setRelation('section', $section);

        $tuition = new AssessmentItem([
            'id' => 2,
            'category' => AssessmentItemCategory::Tuition,
            'label' => 'Tuition',
            'quantity' => '3.00',
            'unit_amount' => '500.00',
            'amount' => '1500.00',
        ]);
        $registration = new AssessmentItem([
            'id' => 3,
            'category' => AssessmentItemCategory::Miscellaneous,
            'label' => 'Registration',
            'amount' => '250.00',
        ]);
        $assessment = new Assessment(['total_amount' => '1750.00', 'currency' => 'PHP']);
        $assessment->setRelation('items', collect([$registration, $tuition]));

        $cashier = new User(['name' => 'Cashier One']);
        $payment = new Payment(['amount' => '1750.00']);
        $payment->setRelation('confirmer', $cashier);
        $enrollment = new Enrollment(['id' => 42, 'total_units' => 3, 'status' => EnrollmentStatus::Enrolled]);
        $enrollment->setRelation('student', $student);
        $enrollment->setRelation('academicTerm', $term);
        $enrollment->setRelation('enrollmentSubjects', collect([$enrollmentSubject]));
        $enrollment->setRelation('assessment', $assessment);

        $snapshot = app(BuildCorSnapshot::class)->execute($enrollment, $payment);

        self::assertSame('Certificate of Registration', $snapshot['document_title']);
        self::assertSame('2026-0001', $snapshot['student']['student_number']);
        self::assertSame("123 Mabini Street\nCaloocan City", $snapshot['student']['address']);
        self::assertSame('CS101', $snapshot['subjects'][0]['code']);
        self::assertSame('10:30 AM - 01:30 PM Mon', $snapshot['subjects'][0]['schedule']);
        self::assertSame('1500.00', $snapshot['fees']['total_tuition']);
        self::assertSame('250.00', $snapshot['fees']['total_other_fees']);
        self::assertSame('1750.00', $snapshot['fees']['grand_total']);
        self::assertSame('Cashier One', $snapshot['signatories']['cashier']);
        self::assertNotEmpty($snapshot['withdrawal_terms']);
        self::assertCount(15, $snapshot['fees']['other_fees']);
        self::assertSame('Registration', $snapshot['fees']['other_fees'][0]['label']);
        self::assertSame('250.00', $snapshot['fees']['other_fees'][0]['amount']);
        self::assertSame('Guidance and Counseling and Student Affair', $snapshot['fees']['other_fees'][1]['label']);
        self::assertSame('0.00', $snapshot['fees']['other_fees'][1]['amount']);
        self::assertSame('Library Fee', $snapshot['fees']['other_fees'][14]['label']);
    }
}

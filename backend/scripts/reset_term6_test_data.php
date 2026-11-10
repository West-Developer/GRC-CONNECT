<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\AccountPayment;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\QueueTicket;
use App\Models\Section;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;

echo "=== GRC Enrollment System — Reset Test Cohort Data for Term 6 ===\n\n";

DB::beginTransaction();

try {
    // 1. Resolve test students
    $testStudents = StudentProfile::where('student_number', 'LIKE', 'TEST-%')->get();
    $testStudentIds = $testStudents->pluck('id');
    $testUserIds = $testStudents->pluck('user_id');

    echo "Found " . $testStudents->count() . " test student profiles.\n";

    // 2. Fix TEST-REG-Y1-01 category to 'regular' if needed
    $regY1 = StudentProfile::where('student_number', 'TEST-REG-Y1-01')->first();
    if ($regY1 && $regY1->enrollment_category !== 'regular') {
        $regY1->update(['enrollment_category' => 'regular']);
        echo "Reset TEST-REG-Y1-01 category to 'regular'.\n";
    }

    // 3. Resolve Term 6 enrollments for test cohort
    $term6Enrollments = Enrollment::where('academic_term_id', 6)->whereIn('student_id', $testStudentIds)->get();
    $term6EnrollmentIds = $term6Enrollments->pluck('id');
    $assessmentIds = Assessment::whereIn('enrollment_id', $term6EnrollmentIds)->pluck('id');

    echo "Found " . $term6Enrollments->count() . " Term 6 enrollments for test cohort.\n\n";

    // 4. Cascade purge
    $deletedGrades = AcademicGrade::where('academic_term_id', 6)->whereIn('student_id', $testStudentIds)->delete();
    echo "1. Deleted Academic Grades in Term 6: {$deletedGrades}\n";

    $deletedDocs = EnrollmentDocument::whereIn('enrollment_id', $term6EnrollmentIds)->delete();
    echo "2. Deleted Enrollment Documents (COR): {$deletedDocs}\n";

    $deletedTickets = QueueTicket::whereIn('enrollment_id', $term6EnrollmentIds)->delete();
    echo "3. Deleted Queue Tickets: {$deletedTickets}\n";

    $deletedAcctPayments = AccountPayment::whereIn('enrollment_id', $term6EnrollmentIds)->delete();
    echo "4. Deleted Account Payments: {$deletedAcctPayments}\n";

    $deletedPayments = Payment::whereIn('enrollment_id', $term6EnrollmentIds)->delete();
    echo "5. Deleted Payments: {$deletedPayments}\n";

    $deletedItems = AssessmentItem::whereIn('assessment_id', $assessmentIds)->delete();
    echo "6. Deleted Assessment Items: {$deletedItems}\n";

    $deletedAssessments = Assessment::whereIn('enrollment_id', $term6EnrollmentIds)->delete();
    echo "7. Deleted Assessments: {$deletedAssessments}\n";

    $deletedSubjects = EnrollmentSubject::whereIn('enrollment_id', $term6EnrollmentIds)->delete();
    echo "8. Deleted Enrollment Subjects: {$deletedSubjects}\n";

    $deletedChanges = DB::table('enrollment_change_requests')->whereIn('enrollment_id', $term6EnrollmentIds)->delete();
    echo "9. Deleted Enrollment Change Requests: {$deletedChanges}\n";

    $deletedWithdrawals = DB::table('withdrawal_requests')->whereIn('enrollment_id', $term6EnrollmentIds)->delete();
    echo "10. Deleted Withdrawal Requests: {$deletedWithdrawals}\n";

    $deletedEnrollments = Enrollment::whereIn('id', $term6EnrollmentIds)->delete();
    echo "11. Deleted Enrollments: {$deletedEnrollments}\n";

    $deletedNotifications = Notification::whereIn('user_id', $testUserIds)->delete();
    echo "12. Deleted Notifications: {$deletedNotifications}\n";

    // 5. Synchronize sections enrolled_count
    DB::statement("
        UPDATE sections s
        SET enrolled_count = (
            SELECT COUNT(*)
            FROM enrollment_subjects es
            JOIN enrollments e ON e.id = es.enrollment_id
            WHERE es.section_id = s.id
              AND e.status = 'enrolled'
        )
        WHERE s.academic_term_id = 6
    ");
    echo "13. Recomputed sections enrolled_count for Term 6.\n";

    DB::commit();
    echo "\n=== TRANSACTION COMMITTED SUCCESSFULLY ===\n\n";

    // 6. Post-verification
    $remainingEnrollments = Enrollment::where('academic_term_id', 6)->whereIn('student_id', $testStudentIds)->count();
    $remainingGrades = AcademicGrade::where('academic_term_id', 6)->whereIn('student_id', $testStudentIds)->count();
    $historicalGrades = AcademicGrade::where('academic_term_id', '!=', 6)->whereIn('student_id', $testStudentIds)->count();
    $term = AcademicTerm::find(6);

    echo "Verification Summary:\n";
    echo "  - Active Term: {$term->school_year} {$term->semester} sem (Status: {$term->status->value})\n";
    echo "  - Test Students Count: " . $testStudents->count() . "\n";
    echo "  - Test Students Term 6 Enrollments: {$remainingEnrollments} (Expected: 0)\n";
    echo "  - Test Students Term 6 Grades: {$remainingGrades} (Expected: 0)\n";
    echo "  - Test Students Historical Grades (Term 5): {$historicalGrades} (Preserved for prerequisites)\n";

} catch (\Throwable $e) {
    DB::rollBack();
    echo "\n[ERROR] Transaction failed and rolled back:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

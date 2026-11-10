<?php

namespace App\Actions\Academic;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\StudentType;
use App\Domain\Notifications\NotificationType;
use App\Models\NoCreditDeclaration;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use App\Support\Notifications\ProgramChairRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A Transferee or Returnee with nothing to credit says so, so the Program Chair can review it and the
 * student can go on to enroll (ADR 0026 / 0050, amended).
 *
 * The student is pinned to the caller. Only a Transferee or a Returnee has a credit mapping to finish, and
 * a student who already has a subject in their credit list (saved, sent, endorsed or approved) is told to submit
 * those instead, so the two routes never disagree. A credit the Chair rejected no longer counts: a Returnee whose
 * only credits were all rejected has nothing left to credit and is otherwise stuck. Idempotent: the profile row is locked first, so a double tap or a second
 * tab finds the same declaration, changes nothing and notifies nobody again.
 */
final readonly class DeclareNoCredit
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    public function execute(User $actor, AuditRequestContext $context): NoCreditDeclaration
    {
        $student = StudentProfile::query()->with('program')->where('user_id', $actor->id)->first();

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Your student record could not be found.']);
        }

        return DB::transaction(function () use ($student, $actor, $context): NoCreditDeclaration {
            // The profile row is the lock for this student's whole credit mapping.
            $locked = StudentProfile::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->student_type, [StudentType::Transferee, StudentType::Returnee], true)) {
                throw ValidationException::withMessages(['student_id' => 'Only a Transferee or a Returnee has credits to map.']);
            }

            $existing = NoCreditDeclaration::query()->where('student_id', $locked->id)->first();

            if ($existing !== null) {
                return $existing->load('student.user');
            }

            if (TransfereeCredit::query()
                ->where('student_id', $locked->id)
                ->where('status', '!=', TransfereeCreditStatus::Rejected->value)
                ->exists()) {
                throw ValidationException::withMessages([
                    'student_id' => 'You already have subjects in your credit list. Submit them to your Program Head instead.',
                ]);
            }

            $declaration = NoCreditDeclaration::create([
                'student_id' => $locked->id,
                'declared_at' => now(),
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::NO_CREDIT_DECLARED,
                AuditableType::STUDENT_PROFILE,
                $locked->id,
                null,
                ['student_id' => $locked->id],
                null,
                $context,
            );

            $this->notificationRecorder->recordMany(
                ProgramChairRecipients::forStudent($student),
                NotificationType::TransfereeNoCreditDeclared,
                sprintf('Student %s has no subject to credit and is waiting for your review.', $student->student_number),
            );

            return $declaration->load('student.user');
        });
    }
}

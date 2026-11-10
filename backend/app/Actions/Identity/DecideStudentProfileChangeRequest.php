<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\PersonName;
use App\Domain\Identity\StudentProfileChangeRequestStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\Notification;
use App\Models\StudentProfile;
use App\Models\StudentProfileChangeRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DecideStudentProfileChangeRequest
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array{action: string, identity_verified_in_person: mixed, notes?: ?string} $data */
    public function handle(
        StudentProfileChangeRequest $changeRequest,
        array $data,
        User $actor,
        AuditRequestContext $context,
    ): StudentProfileChangeRequest {
        return DB::transaction(function () use ($changeRequest, $data, $actor, $context): StudentProfileChangeRequest {
            $locked = StudentProfileChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StudentProfileChangeRequestStatus::Pending) {
                throw new ConflictHttpException('This profile change request has already been decided.');
            }

            $profile = StudentProfile::query()->whereKey($locked->student_id)->lockForUpdate()->firstOrFail();
            $studentUser = User::query()->whereKey($profile->user_id)->lockForUpdate()->firstOrFail();
            $approved = $data['action'] === 'approve';

            if ($approved) {
                if (! $profile->updated_at?->equalTo($locked->base_profile_updated_at)) {
                    throw new ConflictHttpException('The official student profile changed after this request was submitted.');
                }

                $duplicateEmail = User::query()
                    ->where('email', $locked->requested_email)
                    ->whereKeyNot($studentUser->id)
                    ->exists();
                if ($duplicateEmail) {
                    throw ValidationException::withMessages([
                        'email' => 'The requested email address is already in use.',
                    ]);
                }

                $studentUser->forceFill([
                    'name' => PersonName::compose(
                        $locked->requested_first_name,
                        $locked->requested_middle_initial,
                        $locked->requested_last_name,
                        $locked->requested_suffix,
                    ),
                    'first_name' => $locked->requested_first_name,
                    'middle_name' => $locked->requested_middle_name,
                    'middle_initial' => $locked->requested_middle_initial,
                    'last_name' => $locked->requested_last_name,
                    'suffix' => $locked->requested_suffix,
                    'email' => $locked->requested_email,
                ])->save();
                $profile->forceFill(['address' => $locked->requested_address])->save();
            }

            $newStatus = $approved
                ? StudentProfileChangeRequestStatus::Approved
                : StudentProfileChangeRequestStatus::Rejected;
            $locked->forceFill([
                'status' => $newStatus,
                'decided_by' => $actor->id,
                'decision_notes' => $data['notes'] ?? null,
                'identity_verified_at' => now(),
                'decided_at' => now(),
            ])->save();

            Notification::create([
                'user_id' => $studentUser->id,
                'type' => $approved
                    ? NotificationType::StudentProfileChangeApproved
                    : NotificationType::StudentProfileChangeRejected,
                'message' => $approved
                    ? 'Your student information change was approved by Admission.'
                    : 'Your student information change was rejected by Admission. Review the decision notes in Student Information.',
            ]);

            $this->auditRecorder->record(
                $actor,
                $approved
                    ? AuditAction::STUDENT_PROFILE_CHANGE_REQUEST_APPROVED
                    : AuditAction::STUDENT_PROFILE_CHANGE_REQUEST_REJECTED,
                AuditableType::STUDENT_PROFILE_CHANGE_REQUEST,
                $locked->id,
                ['status' => StudentProfileChangeRequestStatus::Pending->value],
                [
                    'status' => $newStatus->value,
                    'changed_field_count' => $approved ? 6 : 0,
                    'decision_notes_provided' => isset($data['notes']) && $data['notes'] !== '',
                ],
                null,
                $context,
            );

            return $locked->refresh()->load(['student.user']);
        });
    }
}

<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\PersonName;
use App\Domain\Identity\StudentProfileChangeRequestStatus;
use App\Models\StudentProfile;
use App\Models\StudentProfileChangeRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManageStudentProfileChangeRequest
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array{first_name: string, middle_name?: ?string, last_name: string, suffix?: ?string, email: string, address: string, reason: string} $data */
    public function create(array $data, User $actor, AuditRequestContext $context): StudentProfileChangeRequest
    {
        return DB::transaction(function () use ($data, $actor, $context): StudentProfileChangeRequest {
            $profile = StudentProfile::query()->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();

            if ($profile->profileChangeRequests()->where('status', StudentProfileChangeRequestStatus::Pending->value)->exists()) {
                throw new ConflictHttpException('A profile change request is already pending.');
            }

            $profile->loadMissing('user');
            $this->ensureChangesOfficialInformation($profile, $data);
            $middle = $this->requestedMiddle($profile, $data);

            $changeRequest = $profile->profileChangeRequests()->create([
                'requested_first_name' => $data['first_name'],
                'requested_middle_name' => $middle['middle_name'],
                'requested_middle_initial' => $middle['middle_initial'],
                'requested_last_name' => $data['last_name'],
                'requested_suffix' => $data['suffix'] ?? null,
                'requested_email' => $data['email'],
                'requested_address' => $data['address'],
                'reason' => $data['reason'],
                'base_profile_updated_at' => $profile->updated_at,
                'status' => StudentProfileChangeRequestStatus::Pending,
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_PROFILE_CHANGE_REQUESTED,
                AuditableType::STUDENT_PROFILE_CHANGE_REQUEST,
                $changeRequest->id,
                null,
                ['requested_field_count' => 6, 'reason_provided' => true],
                null,
                $context,
            );

            return $changeRequest->load(['student.user']);
        });
    }

    /** @param array{first_name: string, middle_name?: ?string, last_name: string, suffix?: ?string, email: string, address: string, reason: string} $data */
    public function revise(
        StudentProfileChangeRequest $changeRequest,
        array $data,
        User $actor,
        AuditRequestContext $context,
    ): StudentProfileChangeRequest {
        return DB::transaction(function () use ($changeRequest, $data, $actor, $context): StudentProfileChangeRequest {
            $locked = StudentProfileChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();
            $profile = StudentProfile::query()->whereKey($locked->student_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StudentProfileChangeRequestStatus::Pending) {
                throw new ConflictHttpException('Only a pending profile change request can be revised.');
            }

            $profile->loadMissing('user');
            $this->ensureChangesOfficialInformation($profile, $data);
            $middle = $this->requestedMiddle($profile, $data);

            $locked->update([
                'requested_first_name' => $data['first_name'],
                'requested_middle_name' => $middle['middle_name'],
                'requested_middle_initial' => $middle['middle_initial'],
                'requested_last_name' => $data['last_name'],
                'requested_suffix' => $data['suffix'] ?? null,
                'requested_email' => $data['email'],
                'requested_address' => $data['address'],
                'reason' => $data['reason'],
                'base_profile_updated_at' => $profile->updated_at,
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_PROFILE_CHANGE_REQUEST_UPDATED,
                AuditableType::STUDENT_PROFILE_CHANGE_REQUEST,
                $locked->id,
                ['requested_field_count' => 6],
                ['requested_field_count' => 6, 'reason_provided' => true],
                null,
                $context,
            );

            return $locked->refresh()->load(['student.user']);
        });
    }

    public function cancel(
        StudentProfileChangeRequest $changeRequest,
        User $actor,
        AuditRequestContext $context,
    ): StudentProfileChangeRequest {
        return DB::transaction(function () use ($changeRequest, $actor, $context): StudentProfileChangeRequest {
            $locked = StudentProfileChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StudentProfileChangeRequestStatus::Pending) {
                throw new ConflictHttpException('Only a pending profile change request can be cancelled.');
            }

            $locked->update([
                'status' => StudentProfileChangeRequestStatus::Cancelled,
                'decided_at' => now(),
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_PROFILE_CHANGE_REQUEST_CANCELLED,
                AuditableType::STUDENT_PROFILE_CHANGE_REQUEST,
                $locked->id,
                ['status' => StudentProfileChangeRequestStatus::Pending->value],
                ['status' => StudentProfileChangeRequestStatus::Cancelled->value],
                null,
                $context,
            );

            return $locked->refresh()->load(['student.user']);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StudentProfileChangeRequest>
     */
    public function list(array $filters, User $actor): LengthAwarePaginator
    {
        return StudentProfileChangeRequest::query()
            ->with(['student.user'])
            ->when($actor->role->value === 'student', fn ($query) => $query
                ->whereHas('student', fn ($studentQuery) => $studentQuery->where('user_id', $actor->id)))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString();
    }

    /**
     * The middle name and initial the request proposes; the profile's own values when the submitted middle name
     * is the one already on record, however it is typed.
     *
     * @param  array{middle_name?: ?string}  $data
     * @return array{middle_name: ?string, middle_initial: ?string}
     */
    private function requestedMiddle(StudentProfile $profile, array $data): array
    {
        return PersonName::middle($data['middle_name'] ?? null, $profile->user->middle_name, $profile->user->middle_initial);
    }

    /** @param array{first_name: string, middle_name?: ?string, last_name: string, suffix?: ?string, email: string, address: string, reason: string} $data */
    private function ensureChangesOfficialInformation(StudentProfile $profile, array $data): void
    {
        if (
            $data['first_name'] === $profile->user->first_name
            && $this->requestedMiddle($profile, $data) === ['middle_name' => $profile->user->middle_name, 'middle_initial' => $profile->user->middle_initial]
            && $data['last_name'] === $profile->user->last_name
            && ($data['suffix'] ?? null) === $profile->user->suffix
            && mb_strtolower($data['email']) === mb_strtolower($profile->user->email)
            && $data['address'] === $profile->address
        ) {
            throw ValidationException::withMessages([
                'profile' => 'At least one proposed value must differ from the official student information.',
            ]);
        }
    }
}

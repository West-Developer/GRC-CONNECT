<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\SectionChangeRequestStatus;
use App\Domain\Scheduling\SectionModality;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Section;
use App\Models\SectionChangeRequest;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14 S12b (ADR 0032): a published section is final for the
 * Program Head; changes go through the Registrar Head, and only the professor
 * can still be swapped directly (with the Registrar Head told).
 */
final class SectionChangeRequestsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function user(UserRole $role, string $email, ?CollegeCode $college = null): User
    {
        return User::create([
            'name' => 'Test '.$role->value.' '.$email,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'college' => $college,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function published(string $code = 'CS101', array $overrides = []): Section
    {
        $term = AcademicTerm::query()->first()
            ?? AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $subject = Subject::create(['code' => $code, 'title' => 'Subject '.$code, 'units' => 3, 'status' => SubjectStatus::Active]);

        return Section::create($overrides + [
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'schedule_days' => 'MON',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'room' => 'R101',
            // Room clashes only count for physical (face-to-face / HyFlex) classes.
            'modality' => SectionModality::FaceToFace,
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);
    }

    /** @return array<string, mixed> */
    private function fullUpdate(Section $section, array $overrides = []): array
    {
        return $overrides + [
            'academic_term_id' => $section->academic_term_id,
            'subject_id' => $section->subject_id,
            'section_code' => $section->section_code,
            'professor_id' => $section->professor_id,
            'schedule_days' => $section->schedule_days,
            'starts_at_time' => $section->starts_at_time,
            'ends_at_time' => $section->ends_at_time,
            'room' => $section->room,
            'modality' => $section->modality?->value,
            'capacity' => $section->capacity,
            'viability_threshold' => null,
            'status' => $section->status->value,
        ];
    }

    private function professor(string $email): User
    {
        return $this->user(UserRole::Faculty, $email);
    }

    // --- the lock on a published section -------------------------------

    public function test_a_program_head_cannot_change_the_schedule_of_a_published_section_directly(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.lock@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->patchJson("/api/v1/sections/{$section->id}", $this->fullUpdate($section, ['room' => 'R202']))
            ->assertForbidden();

        self::assertSame('R101', $section->fresh()->room);
    }

    public function test_a_program_head_cannot_close_a_published_section_directly(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.status@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->patchJson("/api/v1/sections/{$section->id}", $this->fullUpdate($section, ['status' => 'cancelled']))
            ->assertForbidden();
    }

    public function test_a_program_head_can_still_edit_an_unpublished_section(): void
    {
        $section = $this->published(overrides: ['status' => SectionStatus::Planned]);
        $head = $this->user(UserRole::ProgramChair, 'ph.planned@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->patchJson("/api/v1/sections/{$section->id}", $this->fullUpdate($section, ['room' => 'R202']))
            ->assertOk();

        self::assertSame('R202', $section->fresh()->room);
    }

    public function test_a_program_head_can_reassign_the_professor_and_the_registrar_head_is_told(): void
    {
        $section = $this->published();
        $professor = $this->professor('prof.new@grc.test');
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.told@grc.test');
        $head = $this->user(UserRole::ProgramChair, 'ph.reassign@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->patchJson("/api/v1/sections/{$section->id}", $this->fullUpdate($section, ['professor_id' => $professor->id]))
            ->assertOk();

        self::assertSame($professor->id, $section->fresh()->professor_id);
        $notice = Notification::query()->where('user_id', $registrarHead->id)
            ->where('type', NotificationType::SectionProfessorReassigned)->first();
        self::assertNotNull($notice);
        self::assertStringContainsString('CS101', $notice->message);

        $audit = AuditLog::query()->where('action', AuditAction::SECTION_PROFESSOR_REASSIGNED)->first();
        self::assertNotNull($audit);
        self::assertNull($audit->before_values['professor_id']);
        self::assertSame($professor->id, $audit->after_values['professor_id']);
    }

    public function test_swapping_the_professor_of_a_published_section_tells_both_professors(): void
    {
        $section = $this->published();
        $original = $this->professor('prof.original@grc.test');
        $replacement = $this->professor('prof.replacement@grc.test');
        $section->update(['professor_id' => $original->id]);
        $head = $this->user(UserRole::ProgramChair, 'ph.swap@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->patchJson("/api/v1/sections/{$section->id}", $this->fullUpdate($section, ['professor_id' => $replacement->id]))
            ->assertOk();

        $gained = Notification::query()->where('user_id', $replacement->id)->sole();
        self::assertSame(NotificationType::SectionAssigned, $gained->type);
        self::assertStringContainsString('CS101', $gained->message);
        $lost = Notification::query()->where('user_id', $original->id)->sole();
        self::assertSame(NotificationType::SectionProfessorReassigned, $lost->type);
        self::assertStringContainsString('no longer teach', $lost->message);
    }

    public function test_a_first_assignment_after_publication_is_announced_to_the_professor_only_once(): void
    {
        $section = $this->published();
        $professor = $this->professor('prof.once@grc.test');
        $head = $this->user(UserRole::ProgramChair, 'ph.once@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->patchJson("/api/v1/sections/{$section->id}", $this->fullUpdate($section, ['professor_id' => $professor->id]))
            ->assertOk();

        self::assertSame(1, Notification::query()->where('user_id', $professor->id)->count());
    }

    // --- filing a request ----------------------------------------------

    public function test_a_program_head_files_a_change_request_and_the_registrar_head_is_notified(): void
    {
        $section = $this->published();
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.notified@grc.test');
        $head = $this->user(UserRole::ProgramChair, 'ph.file@grc.test', CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($head))
            ->postJson("/api/v1/sections/{$section->id}/change-requests", [
                'reason' => 'Room is being repaired.',
                'changes' => ['room' => 'R202', 'starts_at_time' => '09:00:00', 'ends_at_time' => '11:00:00'],
            ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame('pending', $response->json('data.status'));
        self::assertSame('CS101', $response->json('data.subject_code'));
        $byField = collect($response->json('data.changes'))->keyBy('field');
        self::assertSame('R101', $byField['room']['old']);
        self::assertSame('R202', $byField['room']['new']);
        self::assertSame('Room', $byField['room']['label']);

        // Nothing moved yet.
        self::assertSame('R101', $section->fresh()->room);
        self::assertSame(1, Notification::query()->where('user_id', $registrarHead->id)
            ->where('type', NotificationType::SectionChangeRequested)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::SECTION_CHANGE_REQUESTED)->count());
    }

    public function test_a_second_pending_request_for_the_same_section_is_refused(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.twice@grc.test', CollegeCode::Ccs);
        $token = $this->tokenFor($head);
        $payload = ['reason' => 'Room repair.', 'changes' => ['room' => 'R202']];

        $this->withToken($token)->postJson("/api/v1/sections/{$section->id}/change-requests", $payload)->assertCreated();
        $this->withToken($token)->postJson("/api/v1/sections/{$section->id}/change-requests", $payload)
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['section']]]);

        self::assertSame(1, SectionChangeRequest::query()->count());
    }

    public function test_a_request_that_changes_nothing_is_refused(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.same@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->postJson("/api/v1/sections/{$section->id}/change-requests", ['reason' => 'No change.', 'changes' => ['room' => 'R101']])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['changes']]]);
    }

    public function test_a_request_is_refused_when_the_new_slot_clashes_with_another_class(): void
    {
        $section = $this->published();
        $this->published('CS102', ['section_code' => 'B', 'room' => 'R202', 'schedule_days' => 'MON', 'starts_at_time' => '08:00:00', 'ends_at_time' => '10:00:00']);
        $head = $this->user(UserRole::ProgramChair, 'ph.clash@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->postJson("/api/v1/sections/{$section->id}/change-requests", ['reason' => 'Bigger room.', 'changes' => ['room' => 'R202']])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['room']]]);
    }

    public function test_a_request_cannot_carry_a_field_outside_the_schedule(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.field@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->postJson("/api/v1/sections/{$section->id}/change-requests", ['reason' => 'Swap.', 'changes' => ['status' => 'cancelled']])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['changes.status']]]);
    }

    public function test_a_request_needs_a_published_section(): void
    {
        $section = $this->published(overrides: ['status' => SectionStatus::Planned]);
        $head = $this->user(UserRole::ProgramChair, 'ph.unpub@grc.test', CollegeCode::Ccs);

        $this->withToken($this->tokenFor($head))
            ->postJson("/api/v1/sections/{$section->id}/change-requests", ['reason' => 'Room.', 'changes' => ['room' => 'R202']])
            ->assertForbidden();
    }

    public function test_other_roles_cannot_file_a_request(): void
    {
        $section = $this->published();
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.nofile@grc.test');

        $this->withToken($this->tokenFor($registrarHead))
            ->postJson("/api/v1/sections/{$section->id}/change-requests", ['reason' => 'Room.', 'changes' => ['room' => 'R202']])
            ->assertForbidden();
    }

    // --- deciding -------------------------------------------------------

    private function pendingRequest(Section $section, User $head, array $new = ['room' => 'R202'], array $old = ['room' => 'R101']): SectionChangeRequest
    {
        return SectionChangeRequest::create([
            'section_id' => $section->id,
            'requested_by' => $head->id,
            'status' => SectionChangeRequestStatus::Pending,
            'reason' => 'Room repair.',
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    public function test_the_registrar_head_approves_and_the_change_is_applied_once(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.approved@grc.test', CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.approve@grc.test');
        $change = $this->pendingRequest($section, $head);
        $token = $this->tokenFor($registrarHead);

        $first = $this->withToken($token)->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'approve']);
        $first->assertOk();
        self::assertSame('approved', $first->json('data.status'));
        self::assertSame('R202', $section->fresh()->room);

        // Repeating the same decision changes nothing more.
        $this->withToken($token)->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'approve'])->assertOk();
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::SECTION_CHANGE_APPROVED)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::SECTION_UPDATED)->count());
        self::assertSame(1, Notification::query()->where('user_id', $head->id)
            ->where('type', NotificationType::SectionChangeApproved)->count());

        $updated = AuditLog::query()->where('action', AuditAction::SECTION_UPDATED)->first();
        self::assertSame('R101', $updated->before_values['room']);
        self::assertSame('R202', $updated->after_values['room']);
    }

    public function test_approving_is_refused_when_the_section_changed_since_the_request(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.stale@grc.test', CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.stale@grc.test');
        $change = $this->pendingRequest($section, $head);
        $section->update(['room' => 'R303']);

        $this->withToken($this->tokenFor($registrarHead))
            ->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'approve'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['section']]]);

        self::assertSame('R303', $section->fresh()->room);
        self::assertSame(SectionChangeRequestStatus::Pending, $change->fresh()->status);
    }

    public function test_approving_is_refused_when_the_slot_was_taken_in_the_meantime(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.taken@grc.test', CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.taken@grc.test');
        $change = $this->pendingRequest($section, $head);
        $this->published('CS102', ['section_code' => 'B', 'room' => 'R202']);

        $this->withToken($this->tokenFor($registrarHead))
            ->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'approve'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['room']]]);

        self::assertSame('R101', $section->fresh()->room);
    }

    public function test_the_registrar_head_rejects_with_a_reason_and_the_section_stays_put(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.rejected@grc.test', CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.reject@grc.test');
        $change = $this->pendingRequest($section, $head);
        $token = $this->tokenFor($registrarHead);

        $this->withToken($token)->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'reject'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['decision_reason']]]);

        $this->withToken($token)
            ->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'reject', 'decision_reason' => 'Room is booked for exams.'])
            ->assertOk();

        self::assertSame('R101', $section->fresh()->room);
        self::assertSame(SectionChangeRequestStatus::Rejected, $change->fresh()->status);
        self::assertSame(1, Notification::query()->where('user_id', $head->id)
            ->where('type', NotificationType::SectionChangeRejected)->count());
    }

    public function test_a_decided_request_cannot_be_decided_the_other_way(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.flip@grc.test', CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.flip@grc.test');
        $change = $this->pendingRequest($section, $head);
        $change->update(['status' => SectionChangeRequestStatus::Rejected]);

        $this->withToken($this->tokenFor($registrarHead))
            ->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'approve'])
            ->assertUnprocessable();
        self::assertSame('R101', $section->fresh()->room);
    }

    public function test_a_program_head_cannot_decide_a_request(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.self@grc.test', CollegeCode::Ccs);
        $change = $this->pendingRequest($section, $head);

        $this->withToken($this->tokenFor($head))
            ->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'approve'])
            ->assertForbidden();
        self::assertSame('R101', $section->fresh()->room);
    }

    public function test_the_requester_can_withdraw_their_pending_request(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.owner@grc.test', CollegeCode::Ccs);
        $change = $this->pendingRequest($section, $head);

        $this->withToken($this->tokenFor($head))
            ->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'cancel'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        self::assertSame('R101', $section->fresh()->room);
    }

    public function test_another_program_head_cannot_withdraw_it(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.owner2@grc.test', CollegeCode::Ccs);
        $other = $this->user(UserRole::ProgramChair, 'ph.other@grc.test', CollegeCode::Ccs);
        $change = $this->pendingRequest($section, $head);

        $this->withToken($this->tokenFor($other))
            ->patchJson("/api/v1/section-change-requests/{$change->id}", ['action' => 'cancel'])
            ->assertForbidden();
        self::assertSame(SectionChangeRequestStatus::Pending, $change->fresh()->status);
    }

    // --- listing --------------------------------------------------------

    public function test_the_registrar_head_sees_all_requests(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.list@grc.test', CollegeCode::Ccs);
        $this->pendingRequest($section, $head);
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.list@grc.test');

        $response = $this->withToken($this->tokenFor($registrarHead))->getJson('/api/v1/section-change-requests');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertCount(1, $response->json('data'));
    }

    public function test_registrar_staff_cannot_list_requests(): void
    {
        $staff = $this->user(UserRole::RegistrarStaff, 'rs.list@grc.test');

        $this->withToken($this->tokenFor($staff))->getJson('/api/v1/section-change-requests')->assertForbidden();
    }

    public function test_a_program_head_sees_only_their_own_requests_when_they_have_no_college(): void
    {
        $sectionA = $this->published('CS101');
        $sectionB = $this->published('CS102', ['section_code' => 'B', 'room' => 'R500']);
        $mine = $this->user(UserRole::ProgramChair, 'ph.mine@grc.test');
        $theirs = $this->user(UserRole::ProgramChair, 'ph.theirs@grc.test');
        $this->pendingRequest($sectionA, $mine);
        $this->pendingRequest($sectionB, $theirs, ['room' => 'R600'], ['room' => 'R500']);

        $response = $this->withToken($this->tokenFor($mine))->getJson('/api/v1/section-change-requests');

        $response->assertOk();
        self::assertCount(1, $response->json('data'));
        self::assertSame('CS101', $response->json('data.0.subject_code'));
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $section = $this->published();
        $head = $this->user(UserRole::ProgramChair, 'ph.filter@grc.test', CollegeCode::Ccs);
        $this->pendingRequest($section, $head)->update(['status' => SectionChangeRequestStatus::Rejected]);
        $this->pendingRequest($section, $head);
        $registrarHead = $this->user(UserRole::RegistrarHead, 'rh.filter@grc.test');

        $response = $this->withToken($this->tokenFor($registrarHead))->getJson('/api/v1/section-change-requests?status=pending');

        $response->assertOk();
        self::assertCount(1, $response->json('data'));
        self::assertSame('pending', $response->json('data.0.status'));
    }
}

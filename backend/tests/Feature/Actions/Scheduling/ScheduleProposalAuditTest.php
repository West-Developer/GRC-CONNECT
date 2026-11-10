<?php

namespace Tests\Feature\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ScheduleProposalAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_creating_a_proposal_records_the_exact_audit_event(): void
    {
        $term = $this->makeTerm();
        [$chair, $token] = $this->tokenFor(UserRole::ProgramChair, 'schedule.create@grc.test');

        $response = $this->withHeader('X-Request-ID', 'schedule-create-request')
            ->withToken($token)
            ->postJson('/api/v1/schedule-proposals', [
                'academic_term_id' => $term->id,
            ]);

        $response->assertCreated();
        $proposalId = (int) $response->json('data.id');

        $this->assertProposalAudit(
            AuditAction::SCHEDULE_PROPOSAL_CREATED,
            $proposalId,
            $chair,
            null,
            [
                'academic_term_id' => $term->id,
                'submitted_by' => $chair->id,
                'status' => 'draft',
                'decided_by' => null,
                'decided_at' => null,
                'decision_reason' => null,
            ],
            null,
            'schedule-create-request',
        );
        $this->assertDatabaseCount('notifications', 0);
    }

    #[DataProvider('nonPublicationTransitionProvider')]
    public function test_non_publication_transitions_record_exact_audits(
        string $transition,
        ScheduleProposalStatus $currentStatus,
        ScheduleProposalStatus $targetStatus,
        UserRole $actorRole,
        string $expectedAuditAction,
        ?string $reason,
        int $expectedNotifications,
    ): void {
        Carbon::setTestNow('2026-07-29 09:30:00 UTC');

        try {
            $term = $this->makeTerm();
            $submitter = $this->makeUser(UserRole::ProgramChair, "submitter.{$transition}@grc.test");
            $proposal = $this->makeProposal($term, $submitter, $currentStatus);
            [$actor, $token] = $this->tokenFor($actorRole, "actor.{$transition}@grc.test");

            $payload = ['action' => $transition];

            if ($reason !== null) {
                $payload['decision_reason'] = $reason;
            }

            $this->withHeader('X-Request-ID', "transition-{$transition}-request")
                ->withToken($token)
                ->patchJson("/api/v1/schedule-proposals/{$proposal->id}", $payload)
                ->assertOk()
                ->assertJsonPath('data.status', $targetStatus->value);

            $this->assertProposalAudit(
                $expectedAuditAction,
                $proposal->id,
                $actor,
                [
                    'academic_term_id' => $term->id,
                    'submitted_by' => $submitter->id,
                    'status' => $currentStatus->value,
                    'decided_by' => null,
                    'decided_at' => null,
                    'decision_reason' => null,
                ],
                [
                    'academic_term_id' => $term->id,
                    'submitted_by' => $submitter->id,
                    'status' => $targetStatus->value,
                    'decided_by' => $actor->id,
                    'decided_at' => '2026-07-29T09:30:00Z',
                    'decision_reason' => $reason,
                ],
                $reason,
                "transition-{$transition}-request",
            );
            // ADR 0019 supersedes the original "only publish notifies" rule:
            // `TransitionScheduleProposal` now notifies on every transition
            // that `ScheduleTransitionNotificationPlan` covers. `close` is
            // not in that plan, so it stays silent; the approve/return
            // transitions each reach the submitter, who is the only
            // recipient this fixture actually creates a user for.
            $this->assertDatabaseCount('notifications', $expectedNotifications);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * The trailing int is the expected notification count. Approve and
     * return transitions reach the submitting Chair, who is the only
     * planned recipient this fixture creates a user for; `close` has no
     * entry in `ScheduleTransitionNotificationPlan` at all.
     *
     * @return array<string, array{
     *     string,
     *     ScheduleProposalStatus,
     *     ScheduleProposalStatus,
     *     UserRole,
     *     string,
     *     ?string,
     *     int
     * }>
     */
    public static function nonPublicationTransitionProvider(): array
    {
        return [
            'dean approve' => [
                'dean_approve',
                ScheduleProposalStatus::Draft,
                ScheduleProposalStatus::DeanApproved,
                UserRole::Dean,
                AuditAction::SCHEDULE_PROPOSAL_DEAN_APPROVED,
                null,
                1,
            ],
            'dean return' => [
                'dean_return',
                ScheduleProposalStatus::Draft,
                ScheduleProposalStatus::Draft,
                UserRole::Dean,
                AuditAction::SCHEDULE_PROPOSAL_DEAN_RETURNED,
                'Please correct the faculty assignments.',
                1,
            ],
            'executive return' => [
                'executive_return',
                ScheduleProposalStatus::DeanApproved,
                ScheduleProposalStatus::Draft,
                UserRole::ExecutiveDirector,
                AuditAction::SCHEDULE_PROPOSAL_EXECUTIVE_RETURNED,
                'Please resolve the room conflict.',
                1,
            ],
            'close' => [
                'close',
                ScheduleProposalStatus::Published,
                ScheduleProposalStatus::Closed,
                UserRole::RegistrarHead,
                AuditAction::SCHEDULE_PROPOSAL_CLOSED,
                null,
                0,
            ],
        ];
    }

    public function test_publication_audits_each_changed_section_and_notifies_each_unique_recipient_once(): void
    {
        Carbon::setTestNow('2026-07-29 10:15:00 UTC');

        try {
            $term = $this->makeTerm();
            $subject = $this->makeSubject();
            $chair = $this->makeUser(UserRole::ProgramChair, 'publication.chair@grc.test');
            $facultyA = $this->makeUser(UserRole::Faculty, 'publication.faculty-a@grc.test');
            $facultyB = $this->makeUser(UserRole::Faculty, 'publication.faculty-b@grc.test');
            $alreadyPublishedFaculty = $this->makeUser(UserRole::Faculty, 'publication.already@grc.test');
            $proposal = $this->makeProposal($term, $chair, ScheduleProposalStatus::DeanApproved);

            $plannedSections = [
                $this->makeSection($term, $subject, 'A', $facultyA),
                $this->makeSection($term, $subject, 'B', $facultyA),
                $this->makeSection($term, $subject, 'C', $facultyB),
                $this->makeSection($term, $subject, 'D', null),
                $this->makeSection($term, $subject, 'E', $chair),
            ];
            $alreadyPublished = $this->makeSection(
                $term,
                $subject,
                'F',
                $alreadyPublishedFaculty,
                SectionStatus::Published,
            );
            [$executive, $token] = $this->tokenFor(
                UserRole::ExecutiveDirector,
                'publication.executive@grc.test',
            );

            $this->withHeader('X-Request-ID', 'schedule-publish-request')
                ->withToken($token)
                ->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'publish'])
                ->assertOk()
                ->assertJsonPath('data.status', 'published');

            $publishedSectionIds = array_map(
                static fn (Section $section): int => $section->id,
                $plannedSections,
            );

            foreach ($plannedSections as $section) {
                self::assertSame(SectionStatus::Published, $section->refresh()->status);
            }

            self::assertSame(SectionStatus::Published, $alreadyPublished->refresh()->status);

            $audits = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->orderBy('id')->get();

            self::assertSame(
                [
                    AuditAction::SECTION_PUBLISHED,
                    AuditAction::SECTION_PUBLISHED,
                    AuditAction::SECTION_PUBLISHED,
                    AuditAction::SECTION_PUBLISHED,
                    AuditAction::SECTION_PUBLISHED,
                    AuditAction::SCHEDULE_PROPOSAL_PUBLISHED,
                ],
                $audits->pluck('action')->all(),
            );

            foreach ($audits->take(5)->values() as $index => $audit) {
                self::assertSame(AuditableType::SECTION, $audit->auditable_type);
                self::assertSame($publishedSectionIds[$index], $audit->auditable_id);
                self::assertSame($executive->id, $audit->actor_user_id);
                self::assertSame(['status' => 'planned'], $audit->before_values);
                self::assertSame(['status' => 'published'], $audit->after_values);
                self::assertNull($audit->reason);
                self::assertSame('schedule-publish-request', $audit->request_id);
                self::assertSame('127.0.0.1', $audit->ip_address);
            }

            $proposalAudit = $audits->last();

            self::assertNotNull($proposalAudit);
            self::assertSame(AuditableType::SCHEDULE_PROPOSAL, $proposalAudit->auditable_type);
            self::assertSame($proposal->id, $proposalAudit->auditable_id);
            self::assertSame($executive->id, $proposalAudit->actor_user_id);
            self::assertSame([
                'academic_term_id' => $term->id,
                'submitted_by' => $chair->id,
                'status' => 'dean_approved',
                'decided_by' => null,
                'decided_at' => null,
                'decision_reason' => null,
            ], $proposalAudit->before_values);
            self::assertSame([
                'academic_term_id' => $term->id,
                'submitted_by' => $chair->id,
                'status' => 'published',
                'decided_by' => $executive->id,
                'decided_at' => '2026-07-29T10:15:00Z',
                'decision_reason' => null,
                'published_section_ids' => $publishedSectionIds,
            ], $proposalAudit->after_values);
            self::assertNull($proposalAudit->reason);
            self::assertSame('schedule-publish-request', $proposalAudit->request_id);
            self::assertSame('127.0.0.1', $proposalAudit->ip_address);

            $notifications = Notification::query()->orderBy('user_id')->get();
            $expectedRecipientIds = [$chair->id, $facultyA->id, $facultyB->id];
            sort($expectedRecipientIds);

            self::assertSame($expectedRecipientIds, $notifications->pluck('user_id')->all());
            self::assertSame(3, $notifications->count());

            foreach ($notifications as $notification) {
                self::assertSame(NotificationType::SchedulePublished, $notification->type);
                self::assertSame(
                    'Schedule for 2026-2027 1st has been published.',
                    $notification->message,
                );
                self::assertStringNotContainsString('@', $notification->message);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rejected_transition_creates_no_audit_or_notification(): void
    {
        $term = $this->makeTerm();
        $chair = $this->makeUser(UserRole::ProgramChair, 'rejected.submitter@grc.test');
        $proposal = $this->makeProposal($term, $chair, ScheduleProposalStatus::Draft);
        [, $token] = $this->tokenFor(UserRole::ExecutiveDirector, 'rejected.executive@grc.test');

        $this->withToken($token)
            ->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'publish'])
            ->assertUnprocessable();

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
        $this->assertDatabaseCount('notifications', 0);
        self::assertSame(ScheduleProposalStatus::Draft, $proposal->refresh()->status);
    }

    public function test_audit_failure_rolls_back_proposal_creation(): void
    {
        $term = $this->makeTerm();
        [, $token] = $this->tokenFor(UserRole::ProgramChair, 'rollback.create@grc.test');

        $this->assertAuditFailure(function () use ($term, $token): void {
            $this->withToken($token)
                ->postJson('/api/v1/schedule-proposals', [
                    'academic_term_id' => $term->id,
                ]);
        });

        $this->assertDatabaseCount('schedule_proposals', 0);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_audit_failure_rolls_back_publication_and_every_section(): void
    {
        [$proposal, $sections, $token] = $this->publicationRollbackFixture('audit');

        $this->assertAuditFailure(function () use ($proposal, $token): void {
            $this->withToken($token)
                ->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'publish']);
        });

        self::assertSame(ScheduleProposalStatus::DeanApproved, $proposal->refresh()->status);

        foreach ($sections as $section) {
            self::assertSame(SectionStatus::Planned, $section->refresh()->status);
        }

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_failure_rolls_back_publication_sections_and_audits(): void
    {
        [$proposal, $sections, $token] = $this->publicationRollbackFixture('notification');

        Notification::creating(static function (): never {
            throw new RuntimeException('Injected notification write failure.');
        });
        $this->withoutExceptionHandling();
        $caughtException = null;

        try {
            $this->withToken($token)
                ->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'publish']);
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        } finally {
            Notification::flushEventListeners();
            Notification::clearBootedModels();
        }

        self::assertNotNull($caughtException, 'The injected notification write failure must escape the transaction.');
        self::assertSame('Injected notification write failure.', $caughtException->getMessage());
        self::assertSame(ScheduleProposalStatus::DeanApproved, $proposal->refresh()->status);

        foreach ($sections as $section) {
            self::assertSame(SectionStatus::Planned, $section->refresh()->status);
        }

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
        $this->assertDatabaseCount('notifications', 0);
    }

    /**
     * @param  ?array<string, mixed>  $beforeValues
     * @param  array<string, mixed>  $afterValues
     */
    private function assertProposalAudit(
        string $action,
        int $proposalId,
        User $actor,
        ?array $beforeValues,
        array $afterValues,
        ?string $reason,
        string $requestId,
    ): void {
        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();

        self::assertSame($action, $audit->action);
        self::assertSame(AuditableType::SCHEDULE_PROPOSAL, $audit->auditable_type);
        self::assertSame($proposalId, $audit->auditable_id);
        self::assertSame($actor->id, $audit->actor_user_id);
        self::assertSame($beforeValues, $audit->before_values);
        self::assertSame($afterValues, $audit->after_values);
        self::assertSame($reason, $audit->reason);
        self::assertSame($requestId, $audit->request_id);
        self::assertSame('127.0.0.1', $audit->ip_address);
    }

    /**
     * @param  callable(): void  $operation
     */
    private function assertAuditFailure(callable $operation): void
    {
        AuditLog::creating(static function (): never {
            throw new RuntimeException('Injected audit write failure.');
        });
        $this->withoutExceptionHandling();
        $caughtException = null;

        try {
            $operation();
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }

        self::assertNotNull($caughtException, 'The injected audit write failure must escape the transaction.');
        self::assertSame('Injected audit write failure.', $caughtException->getMessage());
    }

    /** @return array{User, string} */
    private function tokenFor(UserRole $role, string $email): array
    {
        $user = $this->makeUser($role, $email);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        return [$user, $token];
    }

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'name' => 'Schedule '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeSubject(): Subject
    {
        return Subject::create([
            'code' => 'SCHED101',
            'title' => 'Schedule Publication',
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }

    private function makeProposal(
        AcademicTerm $term,
        User $submitter,
        ScheduleProposalStatus $status,
    ): ScheduleProposal {
        return ScheduleProposal::create([
            'academic_term_id' => $term->id,
            'submitted_by' => $submitter->id,
            'status' => $status,
        ]);
    }

    private function makeSection(
        AcademicTerm $term,
        Subject $subject,
        string $code,
        ?User $professor,
        SectionStatus $status = SectionStatus::Planned,
    ): Section {
        return Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => $code,
            'professor_id' => $professor?->id,
            'capacity' => 40,
            'status' => $status,
        ]);
    }

    /**
     * @return array{ScheduleProposal, list<Section>, string}
     */
    private function publicationRollbackFixture(string $suffix): array
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject();
        $chair = $this->makeUser(UserRole::ProgramChair, "rollback.{$suffix}.chair@grc.test");
        $faculty = $this->makeUser(UserRole::Faculty, "rollback.{$suffix}.faculty@grc.test");
        $proposal = $this->makeProposal($term, $chair, ScheduleProposalStatus::DeanApproved);
        $sections = [
            $this->makeSection($term, $subject, 'A', $faculty),
            $this->makeSection($term, $subject, 'B', null),
        ];
        [, $token] = $this->tokenFor(
            UserRole::ExecutiveDirector,
            "rollback.{$suffix}.executive@grc.test",
        );

        return [$proposal, $sections, $token];
    }
}

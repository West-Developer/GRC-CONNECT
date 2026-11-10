<?php

namespace Tests\Feature\Actions\Organization;

use App\Actions\Organization\TransitionAcademicTerm;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TransitionAcademicTermAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_close_then_archive_sets_timestamps_and_releases_the_current_slot(): void
    {
        $actor = User::create([
            'name' => 'Registrar Head',
            'email' => 'term-transition@grc.test',
            'password' => 'password',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);
        $term = AcademicTerm::create([
            'school_year' => '2028-2029',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);

        DB::table('academic_term_current_slots')->where('id', 1)->update([
            'academic_term_id' => $term->id,
        ]);
        $context = new AuditRequestContext('term-transition', '127.0.0.1');

        $action = app(TransitionAcademicTerm::class);
        $closed = $action->execute($term, 'close', $actor, $context);
        $archived = $action->execute($closed, 'archive', $actor, $context);

        self::assertSame(AcademicTermStatus::Archived, $archived->status);
        self::assertNotNull($archived->closed_at);
        self::assertNotNull($archived->archived_at);
        $this->assertDatabaseHas('academic_term_current_slots', ['id' => 1, 'academic_term_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'academic_term.closed', 'auditable_id' => $term->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'academic_term.archived', 'auditable_id' => $term->id]);
    }

    public function test_archive_from_ongoing_closes_and_archives_in_one_action(): void
    {
        $actor = User::create([
            'name' => 'Registrar Head',
            'email' => 'term-invalid-transition@grc.test',
            'password' => 'password',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);
        $term = AcademicTerm::create([
            'school_year' => '2028-2029',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);

        $archived = app(TransitionAcademicTerm::class)->execute(
            $term,
            'archive',
            $actor,
            new AuditRequestContext('term-invalid-transition', '127.0.0.1'),
        );

        self::assertSame(AcademicTermStatus::Archived, $archived->status);
        self::assertNotNull($archived->closed_at);
        self::assertNotNull($archived->archived_at);
    }
}

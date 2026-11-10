<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\Gender;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsChedReportFixtures;
use Tests\TestCase;
use ZipArchive;

final class ChedReportsEndpointTest extends TestCase
{
    use BuildsChedReportFixtures;
    use RefreshDatabase;

    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->term = $this->chedTerm('2025-2026', '2nd');
        $nstp = $this->chedSubject('NSTP2', 'National Service Training Program 2');
        $it = $this->chedSubject('IT101', 'Intro to Computing');

        foreach (['Alpha', 'Bravo', 'Charlie'] as $surname) {
            $student = $this->chedStudent(
                ['last_name' => $surname, 'name' => "X {$surname}"],
                ['gender' => $surname === 'Bravo' ? Gender::Male : Gender::Female],
            );
            $this->chedEnrol($student, $this->term, [$nstp, $it]);
        }
    }

    private function actAs(UserRole $role): User
    {
        $user = $role === UserRole::RegistrarHead
            ? $this->chedRegistrarHead()
            : User::create([
                'name' => 'Test '.$role->value, 'email' => $role->value.'@ched.test', 'password' => 'password',
                'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $report, string $suffix = '', ?int $term = null): string
    {
        return "/api/v1/ched-reports/{$report}{$suffix}?academic_term_id=".($term ?? $this->term->id);
    }

    // ---- who may use it ---------------------------------------------------------------------

    public function test_a_signed_out_request_is_refused(): void
    {
        $this->getJson($this->url('enrollment-list'))->assertStatus(401);
        $this->getJson($this->url('enrollment-list', '/export').'&format=csv')->assertStatus(401);
    }

    public function test_only_the_registrar_head_may_open_or_download_a_report(): void
    {
        foreach ([UserRole::Student, UserRole::RegistrarStaff, UserRole::Dean, UserRole::ExecutiveDirector, UserRole::AdmissionStaff, UserRole::AccountingStaff] as $role) {
            $this->actAs($role);

            $this->getJson($this->url('nstp-enrollment'))->assertStatus(403);
            $this->getJson($this->url('nstp-enrollment', '/export').'&format=csv')->assertStatus(403);
        }
    }

    // ---- preview ----------------------------------------------------------------------------

    public function test_the_registrar_head_previews_a_list_with_its_columns_and_warnings(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $response = $this->getJson($this->url('nstp-enrollment').'&per_page=2');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('report.key', 'nstp-enrollment')
            ->assertJsonPath('report.title', 'NSTP 2 Enrollment (Form 2-B)')
            ->assertJsonPath('report.term_label', '2nd Term, AY 2025-2026')
            ->assertJsonPath('report.school_year', '2025-2026')
            ->assertJsonPath('report.missing_gender', 0)
            ->assertJsonPath('report.demo_populated', 0)
            ->assertJsonPath('report.columns.0', ['key' => 'no', 'label' => 'No.'])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.surname', 'Alpha')
            ->assertJsonPath('data.0.gender', 'FEMALE')
            ->assertJsonPath('data.1.no', 2);
    }

    public function test_the_first_page_is_audited_once_and_later_pages_are_not(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $this->getJson($this->url('nstp-enrollment').'&per_page=1&page=1')->assertOk();
        $this->getJson($this->url('nstp-enrollment').'&per_page=1&page=2')->assertOk();

        $audit = AuditLog::query()->where('action', AuditAction::CHED_REPORT_VIEWED)->get();
        $this->assertCount(1, $audit);
        $this->assertSame('nstp-enrollment', $audit->first()->after_values['report']);
        $this->assertSame(3, $audit->first()->after_values['result_count']);
        $this->assertFalse($audit->first()->after_values['searched']);
    }

    public function test_a_search_narrows_the_preview_and_is_noted_in_the_audit_without_the_words(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $this->getJson($this->url('nstp-enrollment').'&search=alpha')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.surname', 'Alpha');

        $audit = AuditLog::query()->where('action', AuditAction::CHED_REPORT_VIEWED)->get();
        $this->assertCount(1, $audit);
        $this->assertTrue($audit->first()->after_values['searched']);
        $this->assertStringNotContainsString('alpha', strtolower(json_encode($audit->first()->after_values, JSON_THROW_ON_ERROR)));
    }

    public function test_a_search_with_no_match_is_an_empty_page_and_a_download_ignores_it(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $this->getJson($this->url('nstp-enrollment').'&search=nobody')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $csv = $this->get($this->url('nstp-enrollment', '/export').'&format=csv&search=nobody');
        $csv->assertOk();
        $this->assertStringContainsString('Alpha', $csv->streamedContent());
    }

    public function test_an_over_long_search_is_refused(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $response = $this->getJson($this->url('nstp-enrollment').'&search='.str_repeat('a', 101));

        $response->assertStatus(422);
        $this->assertArrayHasKey('search', $response->json('error.errors'));
    }

    public function test_the_summary_previews_as_a_grid(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $this->getJson($this->url('nstp-summary'))
            ->assertOk()
            ->assertJsonPath('report.key', 'nstp-summary')
            ->assertJsonPath('report.columns', [])
            ->assertJsonPath('report.missing_gender', 0)
            ->assertJsonPath('data.missing_gender', 0)
            ->assertJsonPath('data.campus', 'Global Reciprocal Colleges')
            ->assertJsonPath('data.classification', 'Private')
            ->assertJsonPath('data.academic_year', '2025-2026')
            ->assertJsonPath('data.semester', '2nd')
            ->assertJsonPath('data.enrollment.2nd.cwts', ['m' => 1, 'f' => 2])
            ->assertJsonPath('data.enrollment.1st.cwts', ['m' => 0, 'f' => 0])
            ->assertJsonPath('data.graduates.cwts', ['m' => 0, 'f' => 0])
            ->assertJsonPath('data.enrollment.summer.rotc', ['m' => 0, 'f' => 0]);
    }

    public function test_bad_requests_are_refused_cleanly(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $refused = fn (string $url, string $field) => $this->assertArrayHasKey(
            $field,
            $this->getJson($url)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->json('error.errors'),
        );

        $refused('/api/v1/ched-reports/enrollment-list', 'academic_term_id');
        $refused($this->url('enrollment-list', '', 999999), 'academic_term_id');
        $refused($this->url('enrollment-list').'&per_page=500', 'per_page');
        $refused($this->url('enrollment-list', '/export'), 'format');
        $refused($this->url('enrollment-list', '/export').'&format=pdf', 'format');
        $this->getJson($this->url('not-a-report'))->assertStatus(404);
    }

    // ---- downloads --------------------------------------------------------------------------

    public function test_a_csv_download_has_the_header_the_rows_and_a_file_name(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $response = $this->get($this->url('enrollment-list', '/export').'&format=csv');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('CHED-Enrollment-List_2025-2026-2nd.csv', (string) $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFNo.,Programs,Major,", $body);
        $this->assertStringContainsString('Alpha', $body);
        $this->assertStringContainsString('National Service Training Program 2 (3)', $body);
    }

    public function test_an_excel_download_is_the_filled_template(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $response = $this->get($this->url('nstp-enrollment', '/export').'&format=xlsx');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('NSTP2-Form-2B-Enrollment_2025-2026-2nd.xlsx', (string) $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'ched-dl-');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('Alpha', $sheet);
        $this->assertStringContainsString('2nd Semester :    School Year :   2025-2026', $sheet);
        $this->assertStringContainsString('COUNTIF(G14:G16,"MALE")</f><v>1</v>', $sheet);
    }

    public function test_the_summary_downloads_as_csv_and_excel(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $csv = $this->get($this->url('nstp-summary', '/export').'&format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('NSTP-Form-2A-Summary_2025-2026-2nd.csv', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('"Global Reciprocal Colleges",Private', $csv->streamedContent());

        $xlsx = $this->get($this->url('nstp-summary', '/export').'&format=xlsx');
        $xlsx->assertOk();
        $this->assertStringContainsString('NSTP-Form-2A-Summary_2025-2026-2nd.xlsx', (string) $xlsx->headers->get('Content-Disposition'));
    }

    public function test_a_download_is_audited_without_any_student_in_the_record(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $this->get($this->url('nstp-graduates', '/export').'&format=csv')->assertOk()->streamedContent();

        $audit = AuditLog::query()->where('action', AuditAction::CHED_REPORT_EXPORTED)->get();
        $this->assertCount(1, $audit);
        $this->assertSame(
            ['report' => 'nstp-graduates', 'academic_term_id' => $this->term->id, 'format' => 'csv', 'rows' => 0],
            $audit->first()->after_values,
        );
        $this->assertStringNotContainsString('Alpha', json_encode($audit->first()->toArray(), JSON_THROW_ON_ERROR));
    }
}

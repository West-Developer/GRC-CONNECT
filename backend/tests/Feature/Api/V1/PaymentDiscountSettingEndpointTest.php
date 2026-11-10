<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\PaymentDiscountSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PaymentDiscountSettingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/payment-discount-settings';

    private function actAs(UserRole $role): User
    {
        $user = User::create([
            'name' => 'Test '.$role->value, 'email' => 'actor.'.$role->value.'.discount@grc.test', 'password' => 'secret-password-1',
            'role' => $role, 'status' => UserStatus::Active,
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_anonymous_requests_are_unauthenticated(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->putJson(self::URL, ['percentage' => 10, 'valid_from' => null, 'valid_until' => null])->assertUnauthorized();
    }

    public function test_other_roles_can_neither_read_nor_change_it(): void
    {
        foreach ([UserRole::Student, UserRole::RegistrarStaff, UserRole::Dean, UserRole::ProgramChair, UserRole::ItAdmin] as $role) {
            $this->actAs($role);
            $this->getJson(self::URL)->assertForbidden();
            $this->putJson(self::URL, ['percentage' => 50, 'valid_from' => null, 'valid_until' => null])->assertForbidden();
        }

        self::assertSame(0, PaymentDiscountSetting::query()->count());
    }

    public function test_accounting_staff_may_read_but_not_change_it(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.percentage', '10.0');
        $this->putJson(self::URL, ['percentage' => 50, 'valid_from' => null, 'valid_until' => null])->assertForbidden();
        self::assertSame('10.0', PaymentDiscountSetting::current()->percentage);
    }

    public function test_the_registrar_head_reads_the_default_ten_percent_with_no_dates(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'payment_discount_setting')
            ->assertJsonPath('data.percentage', '10.0')
            ->assertJsonPath('data.valid_from', null)
            ->assertJsonPath('data.valid_until', null)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_the_registrar_head_changes_the_percentage_and_the_window_and_it_is_audited(): void
    {
        $head = $this->actAs(UserRole::RegistrarHead);

        $this->putJson(self::URL, ['percentage' => 12.5, 'valid_from' => '2026-10-01', 'valid_until' => '2026-12-31'])
            ->assertOk()
            ->assertJsonPath('data.percentage', '12.5')
            ->assertJsonPath('data.valid_from', '2026-10-01')
            ->assertJsonPath('data.valid_until', '2026-12-31')
            ->assertJsonPath('data.updated_by', $head->name);

        $setting = PaymentDiscountSetting::current();
        self::assertSame('12.5', $setting->percentage);
        self::assertSame($head->id, $setting->updated_by);

        $log = AuditLog::query()->where('action', AuditAction::PAYMENT_DISCOUNT_SETTING_UPDATED)->sole();
        self::assertSame($head->id, $log->actor_user_id);
        self::assertIsArray($log->before_values);
        self::assertIsArray($log->after_values);
        self::assertSame('10.0', $log->before_values['percentage']);
        self::assertSame('12.5', $log->after_values['percentage']);
        self::assertSame('2026-12-31', $log->after_values['valid_until']);
    }

    public function test_saving_the_same_values_writes_no_audit_row(): void
    {
        $this->actAs(UserRole::RegistrarHead);

        $this->putJson(self::URL, ['percentage' => 10, 'valid_from' => null, 'valid_until' => null])->assertOk();
        $this->putJson(self::URL, ['percentage' => 10.0, 'valid_from' => null, 'valid_until' => null])->assertOk();

        self::assertSame(0, AuditLog::query()->where('action', AuditAction::PAYMENT_DISCOUNT_SETTING_UPDATED)->count());
    }

    public function test_the_status_follows_the_window_and_zero_turns_it_off(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
        $this->actAs(UserRole::RegistrarHead);

        $this->putJson(self::URL, ['percentage' => 10, 'valid_from' => '2026-10-13', 'valid_until' => null])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->putJson(self::URL, ['percentage' => 10, 'valid_from' => null, 'valid_until' => '2026-10-11'])
            ->assertOk()->assertJsonPath('data.status', 'expired');
        $this->putJson(self::URL, ['percentage' => 0, 'valid_from' => null, 'valid_until' => null])
            ->assertOk()->assertJsonPath('data.status', 'off');
    }

    public function test_invalid_input_is_refused_and_nothing_changes(): void
    {
        $this->actAs(UserRole::RegistrarHead);
        PaymentDiscountSetting::current();

        $invalid = [
            'a negative percentage' => ['percentage' => -1, 'valid_from' => null, 'valid_until' => null, 'field' => 'percentage'],
            'over one hundred' => ['percentage' => 100.1, 'valid_from' => null, 'valid_until' => null, 'field' => 'percentage'],
            'two decimals' => ['percentage' => '10.55', 'valid_from' => null, 'valid_until' => null, 'field' => 'percentage'],
            'not a number' => ['percentage' => 'ten', 'valid_from' => null, 'valid_until' => null, 'field' => 'percentage'],
            'a bad date' => ['percentage' => 10, 'valid_from' => '12/10/2026', 'valid_until' => null, 'field' => 'valid_from'],
            'an impossible date' => ['percentage' => 10, 'valid_from' => '2026-02-30', 'valid_until' => null, 'field' => 'valid_from'],
            'an end before the start' => ['percentage' => 10, 'valid_from' => '2026-10-20', 'valid_until' => '2026-10-10', 'field' => 'valid_until'],
        ];

        foreach ($invalid as $label => $case) {
            $field = $case['field'];
            unset($case['field']);

            $this->putJson(self::URL, $case)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
            self::assertArrayHasKey($field, $this->putJson(self::URL, $case)->json('error.errors'), $label);
        }

        // Both date keys must be present (null clears a date); omitting one is a mistake, not "no change".
        $this->putJson(self::URL, ['percentage' => 10])->assertUnprocessable();

        self::assertSame('10.0', PaymentDiscountSetting::current()->percentage);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::PAYMENT_DISCOUNT_SETTING_UPDATED)->count());
    }
}

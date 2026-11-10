<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Billing\UpdatePaymentDiscountSettingRequest;
use App\Http\Resources\Api\V1\Billing\PaymentDiscountSettingResource;
use App\Models\PaymentDiscountSetting;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The full-payment discount's percentage and validity (ADR 0046). The Registrar Head edits it;
 * Accounting Staff may read it (`PaymentDiscountSettingPolicy`).
 */
final class PaymentDiscountSettingController extends Controller
{
    /** @throws AuthenticationException */
    public function show(Request $request): JsonResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('view', PaymentDiscountSetting::class);

        // `current()` may create the row on the very first read, and a resource answers 201 for a
        // model created in this request: this is a read, so it is always a 200.
        return $this->noStore(
            PaymentDiscountSettingResource::make(PaymentDiscountSetting::current()->load('updater'))
                ->response($request)
                ->setStatusCode(200),
        );
    }

    /** @throws AuthenticationException */
    public function update(
        UpdatePaymentDiscountSettingRequest $request,
        AuditRecorder $auditRecorder,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('update', PaymentDiscountSetting::class);
        $validated = $request->validated();

        $setting = DB::transaction(function () use ($validated, $actor, $auditRecorder, $contextFactory, $request): PaymentDiscountSetting {
            PaymentDiscountSetting::current();
            $setting = PaymentDiscountSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();

            $before = self::snapshot($setting);
            /** @var numeric-string $percentage */
            $percentage = (string) $validated['percentage'];
            $after = [
                'percentage' => bcadd($percentage, '0', 1),
                'valid_from' => $validated['valid_from'],
                'valid_until' => $validated['valid_until'],
            ];

            // A save that changes nothing leaves no audit record (like FeeScheduleController).
            if ($before === $after) {
                return $setting;
            }

            $setting->forceFill([...$after, 'updated_by' => $actor->id])->save();
            $setting->refresh();

            $auditRecorder->record(
                $actor,
                AuditAction::PAYMENT_DISCOUNT_SETTING_UPDATED,
                AuditableType::PAYMENT_DISCOUNT_SETTING,
                $setting->id,
                $before,
                self::snapshot($setting),
                null,
                $contextFactory->fromRequest($request),
            );

            return $setting;
        });

        return $this->noStore(PaymentDiscountSettingResource::make($setting->load('updater'))->response($request));
    }

    /**
     * @return array{percentage: string, valid_from: ?string, valid_until: ?string}
     */
    private static function snapshot(PaymentDiscountSetting $setting): array
    {
        return [
            'percentage' => bcadd((string) $setting->percentage, '0', 1),
            'valid_from' => $setting->valid_from?->toDateString(),
            'valid_until' => $setting->valid_until?->toDateString(),
        ];
    }

    /** @throws AuthenticationException */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

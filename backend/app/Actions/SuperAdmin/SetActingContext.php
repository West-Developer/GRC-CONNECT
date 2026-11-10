<?php

namespace App\Actions\SuperAdmin;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\ActingContext;
use App\Http\Middleware\AssignRequestId;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\Request;
use LogicException;

final class SetActingContext
{
    public function __construct(
        private AuditRecorder $auditRecorder,
    ) {}

    public function execute(User $user, ActingContext $context, Request $request): User
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new LogicException('A personal access token is required to set acting context.');
        }

        $from = [
            'role' => $token->acting_role,
            'college' => $token->acting_college,
        ];

        $to = [
            'role' => $context->role->value,
            'college' => $context->college?->value,
        ];

        $token->update([
            'acting_role' => $context->role->value,
            'acting_college' => $context->college?->value,
        ]);

        $auditContext = new AuditRequestContext(
            AssignRequestId::getOrCreate($request),
            $request->ip(),
        );

        $this->auditRecorder->record(
            $user,
            AuditAction::SUPER_ADMIN_ACTING_CONTEXT_CHANGED,
            AuditableType::USER_ACCOUNT,
            $user->id,
            $from,
            $to,
            null,
            $auditContext,
        );

        $user->applyActingContext($context);

        return $user;
    }
}

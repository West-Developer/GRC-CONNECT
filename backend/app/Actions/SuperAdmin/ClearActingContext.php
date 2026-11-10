<?php

namespace App\Actions\SuperAdmin;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditRequestContext;
use App\Http\Middleware\AssignRequestId;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\Request;
use LogicException;

final class ClearActingContext
{
    public function __construct(
        private AuditRecorder $auditRecorder,
    ) {}

    public function execute(User $user, Request $request): User
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new LogicException('A personal access token is required to clear acting context.');
        }

        $from = [
            'role' => $token->acting_role,
            'college' => $token->acting_college,
        ];

        $to = [
            'role' => null,
            'college' => null,
        ];

        $token->update([
            'acting_role' => null,
            'acting_college' => null,
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

        $user->applyActingContext(null);

        return $user;
    }
}

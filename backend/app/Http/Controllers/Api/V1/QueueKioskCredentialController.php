<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\QueueKiosk\ChangeQueueKioskPassword;
use App\Actions\QueueKiosk\ViewQueueKioskCredential;
use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QueueKioskCredential\UpdateQueueKioskCredentialRequest;
use App\Http\Resources\Api\V1\QueueKioskCredentialResource;
use App\Models\QueueKioskCredential;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final class QueueKioskCredentialController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function show(
        Request $request,
        ViewQueueKioskCredential $viewQueueKioskCredential,
        AuditRequestContextFactory $contextFactory,
    ): QueueKioskCredentialResource {
        $actor = $this->authenticatedUser($request);
        $credential = $this->credential();
        $this->authorize('view', $credential);

        return new QueueKioskCredentialResource(
            $viewQueueKioskCredential->execute($credential, $actor, $contextFactory->fromRequest($request)),
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateQueueKioskCredentialRequest $request,
        ChangeQueueKioskPassword $changeQueueKioskPassword,
        AuditRequestContextFactory $contextFactory,
    ): QueueKioskCredentialResource {
        $actor = $this->authenticatedUser($request);
        $credential = $this->credential();
        $this->authorize('update', $credential);

        return new QueueKioskCredentialResource(
            $changeQueueKioskPassword->execute(
                $credential,
                (string) $request->validated('password'),
                $actor,
                $contextFactory->fromRequest($request),
            ),
        );
    }

    private function credential(): QueueKioskCredential
    {
        return QueueKioskCredential::query()
            ->whereHas('user', fn ($query) => $query->where('role', UserRole::QueueKiosk))
            ->with('user')
            ->sole();
    }

    /**
     * @throws AuthenticationException
     */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}

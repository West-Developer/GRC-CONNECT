<?php

namespace App\Http\Controllers\Api\V1\ItControl;

use App\Actions\ItControl\ArchiveManagedAccount;
use App\Actions\ItControl\ChangeManagedAccountPassword;
use App\Actions\ItControl\DeleteUnusedManagedAccount;
use App\Actions\ItControl\RestoreManagedAccount;
use App\Actions\ItControl\UpdateManagedAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ItControl\ChangeAccountPasswordRequest;
use App\Http\Requests\Api\V1\ItControl\UpdateAccountRequest;
use App\Http\Resources\Api\V1\ItControl\AccountResource;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IT Control edits, archives, restores and deletes Student and Faculty accounts (ADR 0054). Every ability goes
 * through the `manage-it-control-account` gate, which refuses any other role's account.
 */
final class AccountController extends Controller
{
    public function changePassword(
        ChangeAccountPasswordRequest $request,
        User $user,
        ChangeManagedAccountPassword $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $updated = $action->execute($user, (string) $request->validated('password'), $this->actor($request), $contextFactory->fromRequest($request));

        return $this->respond($request, $updated);
    }

    public function update(
        UpdateAccountRequest $request,
        User $user,
        UpdateManagedAccount $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $updated = $action->execute($user, $request->validated(), $this->actor($request), $contextFactory->fromRequest($request));

        return $this->respond($request, $updated);
    }

    public function archive(
        Request $request,
        User $user,
        ArchiveManagedAccount $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $this->authorize('manage-it-control-account', $user);

        return $this->respond($request, $action->execute($user, $this->actor($request), $contextFactory->fromRequest($request)));
    }

    public function restore(
        Request $request,
        User $user,
        RestoreManagedAccount $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $this->authorize('manage-it-control-account', $user);

        return $this->respond($request, $action->execute($user, $this->actor($request), $contextFactory->fromRequest($request)));
    }

    public function destroy(
        Request $request,
        User $user,
        DeleteUnusedManagedAccount $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $this->authorize('manage-it-control-account', $user);

        $action->execute($user, $this->actor($request), $contextFactory->fromRequest($request));

        return response()->json(['data' => ['id' => $user->id, 'deleted' => true]])
            ->header('Cache-Control', 'no-store, private');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }

    private function respond(Request $request, User $account): JsonResponse
    {
        $response = AccountResource::make($account)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

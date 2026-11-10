<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\SuperAdmin\ClearActingContext;
use App\Actions\SuperAdmin\SetActingContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateActingContextRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

final class ActingContextController extends Controller
{
    public function update(
        UpdateActingContextRequest $request,
        SetActingContext $action,
    ): UserResource {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $updatedUser = $action->execute($user, $request->actingContext(), $request);

        return UserResource::make($updatedUser);
    }

    public function destroy(
        Request $request,
        ClearActingContext $action,
    ): UserResource {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $updatedUser = $action->execute($user, $request);

        return UserResource::make($updatedUser);
    }
}

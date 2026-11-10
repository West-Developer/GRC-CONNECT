<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Dashboard\BuildPolicySettingsSummary;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Dashboard\PolicySettingsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PolicySettingsController extends Controller
{
    public function __invoke(
        Request $request,
        BuildPolicySettingsSummary $buildPolicySettingsSummary,
    ): JsonResponse {
        $this->authorize('view-policy-settings');

        $summary = $buildPolicySettingsSummary->execute();

        $response = PolicySettingsResource::make($summary)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

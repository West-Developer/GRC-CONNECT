<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HealthResource;

final class HealthController extends Controller
{
    public function __invoke(): HealthResource
    {
        return HealthResource::make([
            'type' => 'service-health',
            'service' => 'grc-enrollment-api',
            'status' => 'ok',
            'api_version' => 'v1',
            'generated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}

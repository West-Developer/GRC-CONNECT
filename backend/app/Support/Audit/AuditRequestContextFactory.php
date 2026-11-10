<?php

namespace App\Support\Audit;

use App\Domain\Audit\AuditRequestContext;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;

final class AuditRequestContextFactory
{
    public function fromRequest(Request $request): AuditRequestContext
    {
        return new AuditRequestContext(
            AssignRequestId::getOrCreate($request),
            $request->ip(),
        );
    }
}

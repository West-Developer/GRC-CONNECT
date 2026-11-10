<?php

namespace App\Domain\Audit;

final readonly class AuditRequestContext
{
    public function __construct(
        public string $requestId,
        public ?string $ipAddress,
    ) {}
}

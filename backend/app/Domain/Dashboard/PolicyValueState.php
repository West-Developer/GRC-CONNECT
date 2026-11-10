<?php

namespace App\Domain\Dashboard;

final readonly class PolicyValueState
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $currentValue,
        public PolicyValueStatus $status,
        public string $description,
        public ?string $prdReference,
    ) {}
}

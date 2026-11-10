<?php

namespace App\Domain\Dashboard;

final readonly class PolicySettingsSummary
{
    /**
     * @param  list<PolicyValueState>  $values
     */
    public function __construct(
        public array $values,
    ) {}
}

<?php

namespace App\Domain\Organization;

enum SectionPlanStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
}

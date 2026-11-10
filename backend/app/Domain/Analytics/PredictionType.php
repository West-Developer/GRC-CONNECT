<?php

namespace App\Domain\Analytics;

enum PredictionType: string
{
    case SectionDemand = 'section_demand';
    case Attrition = 'attrition';
}

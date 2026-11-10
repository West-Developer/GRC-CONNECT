<?php

namespace App\Domain\Analytics;

enum PredictionRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}

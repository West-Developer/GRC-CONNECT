<?php

namespace App\Domain\Scheduling;

final class CanonicalScheduleDays
{
    /** @var array<int, string> */
    private const LABELS = [
        1 => 'MON',
        2 => 'TUE',
        3 => 'WED',
        4 => 'THU',
        5 => 'FRI',
        6 => 'SAT',
        7 => 'SUN',
    ];

    public function normalize(?string $scheduleDays): ?string
    {
        $value = trim((string) $scheduleDays);

        if ($value === '') {
            return null;
        }

        $parser = new ScheduleDayParser;
        $days = [];
        $segments = preg_split('/[\s,\/;&|]+/', $value) ?: [];

        foreach ($segments as $segment) {
            foreach ($parser->parse($segment) as $dayOfWeek) {
                $days[$dayOfWeek] = self::LABELS[$dayOfWeek];
            }
        }

        return $days === [] ? null : implode('/', $days);
    }
}

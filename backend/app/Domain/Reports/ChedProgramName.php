<?php

namespace App\Domain\Reports;

/**
 * The CHED forms want the program written in full and the major in its own column ("Please do
 * not abbreviate"). The catalog stores `BS Business Administration major in Financial
 * Management`; this writes the degree out and splits off the major (ADR 0049).
 */
final class ChedProgramName
{
    /** @var array<string, string> leading abbreviation => its full form */
    private const DEGREES = [
        'BS' => 'Bachelor of Science in',
        'BA' => 'Bachelor of Arts in',
    ];

    /**
     * @return array{program: string, major: string}
     */
    public static function split(string $storedName): array
    {
        $name = trim($storedName);
        $major = '';

        if (preg_match('/^(.*?)\s+major\s+in\s+(.+)$/i', $name, $matches) === 1) {
            $name = trim($matches[1]);
            $major = trim($matches[2]);
        }

        foreach (self::DEGREES as $abbreviation => $full) {
            if (preg_match('/^'.$abbreviation.'\s+(.+)$/', $name, $matches) === 1) {
                $name = $full.' '.trim($matches[1]);
                break;
            }
        }

        return ['program' => $name, 'major' => $major];
    }
}

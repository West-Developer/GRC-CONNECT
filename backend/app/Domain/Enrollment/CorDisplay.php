<?php

namespace App\Domain\Enrollment;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Display-only wording for a Certificate of Registration (stakeholder Doc 14).
 *
 * Issued snapshots keep the raw value ("Year 1") on purpose: the COR
 * endpoint rebuilds a snapshot on every read and re-saves the row whenever its
 * hash differs, so changing the stored wording would rewrite almost every COR
 * on its next view. The institution-approved "1st Year" form is therefore
 * applied only when a COR is rendered (PDF here, the portal view in the
 * frontend), for new and legacy snapshots alike.
 */
final class CorDisplay
{
    /**
     * `enrollment_documents.generated_at` is stored UTC like every other
     * timestamp (`config('app.timezone')` stays UTC app-wide on purpose).
     * The PDF used to format that raw UTC value directly while the portal's
     * `toLocaleString()` converted it to the viewer's browser timezone, so a
     * Philippines-based viewer saw two "Generated" times 8 hours apart on the
     * same document (stakeholder Doc 16). Both renderers now go through this
     * single Asia/Manila conversion.
     */
    public static function generatedAt(DateTimeInterface|string $value): string
    {
        return Carbon::parse($value, 'UTC')->timezone('Asia/Manila')->format('m/d/Y, h:i:s A');
    }

    /**
     * `COR_{student name}_{date}.pdf` (stakeholder Doc 16) — the date is the
     * document's own Asia/Manila generation date, not "today", so the same
     * immutable COR always downloads under the same name.
     */
    public static function downloadFilename(string $studentName, DateTimeInterface|string $generatedAt): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($studentName)), '_');
        $date = Carbon::parse($generatedAt, 'UTC')->timezone('Asia/Manila')->format('Y-m-d');

        return sprintf('COR_%s_%s.pdf', $slug, $date);
    }

    /**
     * 1 / "1" / "Year 1" / "1st Year" becomes "1st Year"; empty or
     * non-numeric input is returned unchanged ("—" when empty).
     */
    public static function yearLevel(int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if (preg_match('/\d+/', $trimmed, $match) !== 1) {
                return $trimmed;
            }

            $number = (int) $match[0];
        } else {
            $number = $value;
        }

        return $number > 0 ? self::ordinal($number).' Year' : '—';
    }

    /**
     * Rewrites every "Year N" inside a sentence (the admission certification
     * line) to its ordinal form, leaving the rest untouched.
     */
    public static function sentence(string $text): string
    {
        return (string) preg_replace_callback(
            '/\bYear (\d+)\b/',
            fn (array $match): string => self::yearLevel((int) $match[1]),
            $text,
        );
    }

    private static function ordinal(int $number): string
    {
        $lastTwo = $number % 100;

        if ($lastTwo >= 11 && $lastTwo <= 13) {
            return $number.'th';
        }

        return $number.match ($number % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}

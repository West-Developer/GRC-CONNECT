<?php

namespace App\Support\Http;

use App\Domain\Enrollment\ProfessorVisibility;
use App\Domain\Identity\UserRole;
use App\Models\AcademicTerm;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The one place an API resource asks "may this viewer see the professor of a
 * section in this term?" (stakeholder Doc 14). Only a Student can be denied
 * (`ProfessorVisibility`); everyone else always may.
 *
 * The answer is memoised on the request, one lookup per term per request, so a
 * list of hundreds of sections does not query the term hundreds of times (the
 * app forbids lazy loading in tests). It is deliberately per request, never
 * static: a static memo would leak between requests and between tests.
 */
final class ProfessorDisclosure
{
    private const ATTRIBUTE = 'professor_hidden_by_term';

    public static function hiddenFrom(Request $request, int $academicTermId): bool
    {
        $viewer = $request->user();

        if (! $viewer instanceof User || $viewer->role !== UserRole::Student) {
            return false;
        }

        /** @var array<int, bool> $memo */
        $memo = $request->attributes->get(self::ATTRIBUTE, []);

        if (! array_key_exists($academicTermId, $memo)) {
            $term = AcademicTerm::query()->find($academicTermId);

            $memo[$academicTermId] = $term instanceof AcademicTerm
                && ProfessorVisibility::hiddenFromStudents(
                    $term->status,
                    $term->enrollment_closes_at,
                    $term->add_drop_deadline_at,
                    CarbonImmutable::now(),
                );
            $request->attributes->set(self::ATTRIBUTE, $memo);
        }

        return $memo[$academicTermId];
    }
}

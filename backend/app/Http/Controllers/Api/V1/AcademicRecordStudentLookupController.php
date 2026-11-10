<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicRecord\IndexAcademicRecordStudentRequest;
use App\Http\Resources\Api\V1\AcademicRecordStudentResource;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class AcademicRecordStudentLookupController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(IndexAcademicRecordStudentRequest $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('search-academic-records');

        $search = trim((string) $request->validated('search'));
        $by = (string) ($request->validated('by') ?? 'all');
        $limit = (int) ($request->validated('limit') ?? 20);

        $cleanSearch = trim($search);
        $unhyphenated = str_replace('-', '', $cleanSearch);
        $hyphenated = preg_match('/^\d{11}$/', $cleanSearch)
            ? substr($cleanSearch, 0, 4).'-'.substr($cleanSearch, 4, 2).'-'.substr($cleanSearch, 6, 5)
            : $cleanSearch;

        $students = StudentProfile::query()
            ->with(['user', 'program'])
            // A Program Chair works on their own college's students only
            // (a chair without an assigned college is unscoped, as elsewhere).
            ->when(
                $actor->role === UserRole::ProgramChair && $actor->college !== null,
                fn (Builder $query) => $query->whereHas(
                    'program',
                    fn (Builder $programQuery) => $programQuery->where('college', $actor->college?->value),
                ),
            )
            ->where(function (Builder $query) use ($by, $cleanSearch, $unhyphenated, $hyphenated): void {
                if ($by === 'student_number') {
                    $query->where('student_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('student_number', 'like', "%{$hyphenated}%")
                        ->orWhereRaw("REPLACE(student_number, '-', '') LIKE ?", ["%{$unhyphenated}%"]);

                    return;
                }

                if ($by === 'name') {
                    $query->whereHas('user', function (Builder $userQuery) use ($cleanSearch): void {
                        $userQuery->where('name', 'like', "%{$cleanSearch}%")
                            ->orWhere('first_name', 'like', "%{$cleanSearch}%")
                            ->orWhere('last_name', 'like', "%{$cleanSearch}%");
                    });

                    return;
                }

                $query->where(function (Builder $sub) use ($cleanSearch, $unhyphenated, $hyphenated): void {
                    $sub->where('student_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('student_number', 'like', "%{$hyphenated}%")
                        ->orWhereRaw("REPLACE(student_number, '-', '') LIKE ?", ["%{$unhyphenated}%"])
                        ->orWhereHas('user', function (Builder $userQuery) use ($cleanSearch): void {
                            $userQuery->where('name', 'like', "%{$cleanSearch}%")
                                ->orWhere('first_name', 'like', "%{$cleanSearch}%")
                                ->orWhere('last_name', 'like', "%{$cleanSearch}%");
                        });
                });
            })
            ->orderBy('student_number')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $response = AcademicRecordStudentResource::collection($students)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

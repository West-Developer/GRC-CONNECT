<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BuildStatementOfAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentAccount\ShowStatementOfAccountRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Statement of Account (stakeholder Doc 14, ADR 0036): a Student reads
 * their own; Accounting Staff read the served Student's. Both go through the
 * same `viewAccount` ability as the account balance.
 */
final class StatementOfAccountController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function showOwn(ShowStatementOfAccountRequest $request, BuildStatementOfAccount $build): JsonResponse
    {
        $student = $this->ownProfile($request);
        $this->authorize('viewAccount', $student);

        return $this->json($build->execute($student->load(['user', 'program']), $this->termId($request)));
    }

    /**
     * @throws AuthenticationException
     */
    public function show(ShowStatementOfAccountRequest $request, StudentProfile $student, BuildStatementOfAccount $build): JsonResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('viewAccount', $student);

        return $this->json($build->execute($student->load(['user', 'program']), $this->termId($request)));
    }

    /**
     * @throws AuthenticationException
     */
    public function pdfOwn(ShowStatementOfAccountRequest $request, BuildStatementOfAccount $build): Response
    {
        $student = $this->ownProfile($request);
        $this->authorize('viewAccount', $student);

        return $this->pdfResponse($build->execute($student->load(['user', 'program']), $this->termId($request)));
    }

    /**
     * @throws AuthenticationException
     */
    public function pdf(ShowStatementOfAccountRequest $request, StudentProfile $student, BuildStatementOfAccount $build): Response
    {
        $this->authenticatedUser($request);
        $this->authorize('viewAccount', $student);

        return $this->pdfResponse($build->execute($student->load(['user', 'program']), $this->termId($request)));
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function pdfResponse(array $statement): Response
    {
        $pdf = Pdf::loadView('pdf.statement-of-account', ['statement' => $statement])->setPaper('a4', 'portrait');
        $fileName = sprintf('SOA-%s.pdf', preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $statement['student']['student_number']));

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @throws AuthenticationException
     */
    private function ownProfile(Request $request): StudentProfile
    {
        $actor = $this->authenticatedUser($request);
        $profile = StudentProfile::query()->where('user_id', $actor->id)->first();
        abort_unless($profile instanceof StudentProfile, 403, 'Only a Student has a Statement of Account of their own.');

        return $profile;
    }

    private function termId(ShowStatementOfAccountRequest $request): ?int
    {
        $id = $request->validated('academic_term_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function json(array $statement): JsonResponse
    {
        $response = response()->json(['data' => ['type' => 'statement_of_account', ...$statement]]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @throws AuthenticationException
     */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}

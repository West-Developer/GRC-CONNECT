<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\ListRegistrarStudents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentProfile\IndexRegistrarStudentRequest;
use App\Http\Resources\Api\V1\RegistrarStudentResource;
use App\Models\StudentProfile;
use Illuminate\Http\JsonResponse;

/**
 * Registrar Staff and the Registrar Head's read-only list of every student. There is no write here;
 * the Registrar Head opens one student's details from `students/{id}/registrar-profile`.
 */
final class RegistrarStudentController extends Controller
{
    public function index(IndexRegistrarStudentRequest $request, ListRegistrarStudents $listRegistrarStudents): JsonResponse
    {
        $this->authorize('viewRegistrarStudents', StudentProfile::class);

        $response = RegistrarStudentResource::collection($listRegistrarStudents->execute($request->validated()))->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

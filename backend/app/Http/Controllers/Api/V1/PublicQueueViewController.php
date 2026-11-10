<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\BuildPublicQueueView;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StudentQueueViewResource;
use App\Models\QueueTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR 0044: the live queue behind the link a freshman is emailed at Admission
 * ("view my queue number"), usable without signing in because the freshman has no
 * account yet. The unguessable token in the URL IS the credential: it identifies
 * exactly one ticket, and the response shows that ticket plus the same ticket-
 * number-only board every student sees — never a name, e-mail or student number.
 * An unknown token is a plain 404, indistinguishable from any other missing page.
 * Rate limited per link (`public-queue-view`), not per IP, so a campus full of
 * freshmen behind one network address do not throttle each other.
 */
final class PublicQueueViewController extends Controller
{
    public function show(Request $request, string $token, BuildPublicQueueView $buildPublicQueueView): JsonResponse
    {
        $ticket = QueueTicket::findByPublicToken($token);

        abort_if($ticket === null, 404);

        $view = $buildPublicQueueView->execute($ticket->load('studentProfile'));

        $response = (new StudentQueueViewResource($view))->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

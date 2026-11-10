<?php

namespace App\Http\Resources\Api\V1;

use App\Models\NoCreditDeclaration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student's "no subject to credit". Needs `student.user`. Never exposes who confirmed it: that stays in the
 * audit log, like the other credit resources.
 *
 * @property-read NoCreditDeclaration $resource
 */
final class NoCreditDeclarationResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     id: int,
     *     student_id: int,
     *     student_number: string,
     *     student_name: string,
     *     status: 'pending'|'confirmed',
     *     declared_at: string,
     *     confirmed_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        $declaration = $this->resource;

        return [
            'type' => 'no_credit_declaration',
            'id' => $declaration->id,
            'student_id' => $declaration->student_id,
            'student_number' => $declaration->student->student_number,
            'student_name' => $declaration->student->user->name,
            'status' => $declaration->isConfirmed() ? 'confirmed' : 'pending',
            'declared_at' => $declaration->declared_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'confirmed_at' => $declaration->confirmed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

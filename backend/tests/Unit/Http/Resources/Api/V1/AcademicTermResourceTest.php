<?php

namespace Tests\Unit\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\AcademicTermResource;
use App\Models\AcademicTerm;
use Illuminate\Http\Request;
use Tests\TestCase;

final class AcademicTermResourceTest extends TestCase
{
    public function test_resource_exposes_archive_metadata_and_actionable_current_state(): void
    {
        $term = new AcademicTerm;
        $term->forceFill([
            'id' => 9,
            'school_year' => '2028-2029',
            'semester' => '1st',
            'status' => 'semester_closed',
            'closed_at' => '2028-12-20 00:00:00',
            'archived_at' => null,
        ]);

        $resource = new AcademicTermResource($term);
        $data = $resource->toArray(Request::create('/'));

        self::assertSame('2028-12-20T00:00:00Z', $data['closed_at']);
        self::assertNull($data['archived_at']);
        self::assertFalse($data['is_actionable_current']);
    }
}

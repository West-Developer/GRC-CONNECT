<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sections')
            ->where('modality', 'online')
            ->update([
                'modality' => null,
                'room' => null,
                'recommendation_source' => 'legacy_online_reassignment',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // An old Online-only assignment has no approved physical-week
        // replacement, so a rollback must not invent one.
    }
};

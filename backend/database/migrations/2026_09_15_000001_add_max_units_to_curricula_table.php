<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricula', function (Blueprint $table) {
            $table->decimal('max_units', 4, 1)->nullable()->default(null)->after('status');
        });

        // Initialize default max_units from each curriculum's 1st-4th year placements
        $curricula = \App\Models\Curriculum::with('subjectPlacements.subject')->get();
        foreach ($curricula as $curriculum) {
            $default = $curriculum->defaultMaxUnits();
            if ($default > 0) {
                $curriculum->update(['max_units' => $default]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('curricula', function (Blueprint $table) {
            $table->dropColumn('max_units');
        });
    }
};


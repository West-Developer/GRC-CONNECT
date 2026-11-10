<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One printed line of an `assessments` row — tuition (with `quantity`/
 * `unit_amount` set) or a flat miscellaneous fee (both null, since the fee
 * is not derived from a rate). See `App\Domain\Billing\AssessmentComputation`
 * for how these are computed and `App\Models\AssessmentItem` for why the
 * decimal columns are deliberately not cast to a numeric PHP type.
 *
 * `quantity` is `decimal(6,1)`, matching `subjects.units` and
 * `enrollments.total_units` — Leadership subjects are genuinely 1.5 units,
 * so an integer column here would silently truncate a real tuition line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('label');
            $table->decimal('quantity', 6, 1)->nullable();
            $table->decimal('unit_amount', 10, 2)->nullable();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->index('assessment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_items');
    }
};

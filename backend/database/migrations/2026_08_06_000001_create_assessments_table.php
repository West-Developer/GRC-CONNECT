<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §5.3 process 3.3 "computes the approved assessment". This records
 * what an enrollment was assessed to owe, computed once at Registrar
 * approval by `App\Actions\Billing\AssessEnrollment` — see that Action's
 * docblock for exactly when it runs and why it never recomputes.
 *
 * The UNIQUE constraint on `enrollment_id` is the idempotency mechanism,
 * the same role it plays on `payments` (see that migration's docblock):
 * a second approve attempt cannot create a second assessment.
 *
 * `total_amount` is NOT NULL, unlike `payments.amount` — an assessment
 * with no total is meaningless, whereas an unconfirmed payment's amount
 * is legitimately absent until confirmed. See `App\Models\Assessment`'s
 * docblock for why this column is deliberately not cast to a numeric
 * type in the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_amount', 10, 2);
            $table->string('currency');
            $table->timestamp('assessed_at');
            $table->timestamps();

            $table->unique('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};

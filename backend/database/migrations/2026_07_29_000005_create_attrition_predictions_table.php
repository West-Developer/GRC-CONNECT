<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attrition_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_run_id')->constrained('prediction_runs')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('student_profiles')->restrictOnDelete();
            $table->decimal('risk_probability', 5, 4);
            $table->string('risk_band', 50);
            $table->json('explanations')->nullable();
            $table->timestamps();

            $table->unique(
                ['prediction_run_id', 'student_id'],
                'attrition_predictions_run_student_unique',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE attrition_predictions
            ADD CONSTRAINT attrition_predictions_risk_range
            CHECK (risk_probability BETWEEN 0.0000 AND 1.0000)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attrition_predictions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->foreignId('academic_term_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('model_version', 100);
            $table->string('feature_schema_version', 100);
            $table->string('status', 50);
            $table->json('metrics')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'created_at'], 'prediction_runs_type_status_created_index');
            $table->index(['academic_term_id', 'type', 'created_at'], 'prediction_runs_term_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_runs');
    }
};

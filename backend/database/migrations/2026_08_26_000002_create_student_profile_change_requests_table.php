<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profile_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->string('requested_first_name');
            $table->string('requested_middle_initial', 10)->nullable();
            $table->string('requested_last_name');
            $table->string('requested_suffix', 20)->nullable();
            $table->string('requested_email');
            $table->text('requested_address');
            $table->text('reason');
            $table->timestamp('base_profile_updated_at');
            $table->string('status');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_notes')->nullable();
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profile_change_requests');
    }
};

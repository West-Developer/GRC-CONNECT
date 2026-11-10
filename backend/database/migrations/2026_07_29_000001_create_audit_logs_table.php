<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('request_id', 128);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'audit_logs_auditable_history_index');
            $table->index(['actor_user_id', 'created_at'], 'audit_logs_actor_history_index');
            $table->index(['action', 'created_at'], 'audit_logs_action_history_index');
            $table->index('request_id', 'audit_logs_request_id_index');
            $table->index('created_at', 'audit_logs_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

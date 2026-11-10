<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('account_setup_completed_at')->nullable();
            $table->timestamp('account_setup_invitation_sent_at')->nullable();
            $table->timestamp('account_setup_invitation_failed_at')->nullable();
        });

        // Every account that predates invitation-based provisioning already
        // has a usable password and must remain usable after this migration.
        DB::table('users')->update([
            'account_setup_completed_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
        ]);

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->text('address')->nullable();
            $table->timestamp('requirements_verified_at')->nullable();
            $table->foreignId('requirements_verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requirements_verified_by');
            $table->dropColumn(['address', 'requirements_verified_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'account_setup_completed_at',
                'account_setup_invitation_sent_at',
                'account_setup_invitation_failed_at',
            ]);
        });
    }
};

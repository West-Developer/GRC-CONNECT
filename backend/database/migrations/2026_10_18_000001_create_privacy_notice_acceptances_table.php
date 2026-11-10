<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who agreed to which version of the Data Privacy Notice, when, and how (ADR 0055).
 *
 * `self`     = the person read the notice in the portal and agreed (the only row that stops the sign-in screen
 *              from asking again).
 * `assisted` = Admission confirmed, when creating the account, that the applicant was shown the notice; the
 *              applicant still agrees themself at first sign-in.
 *
 * One row per person, version and method, so agreeing twice (a double click, a second tab) is the same row.
 * The rows are the person's own records: they go with an account that is deleted before it was ever used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_notice_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('notice_version', 32);
            $table->string('method', 16);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at');

            $table->unique(['user_id', 'notice_version', 'method'], 'privacy_acceptances_user_version_method_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_notice_acceptances');
    }
};

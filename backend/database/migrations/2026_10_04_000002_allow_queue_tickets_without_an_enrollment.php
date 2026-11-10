<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A freshman pays the Cashier BEFORE they have an enrollment (and before they
 * can log in), so their queue ticket cannot hang off an enrollment. A ticket may
 * now belong to a student profile instead; one of the two owners is always set
 * (the CHECK), and a profile can hold only one ticket. `public_token_hash` is
 * the SHA-256 of the unguessable token in the "view my queue number" link the
 * freshman is emailed (only the hash is stored). `link_sent_at` /
 * `link_failed_at` record that email's delivery, like the account-setup
 * invitation timestamps on `users`; `dateTime`, not `timestamp`, so MariaDB adds
 * no implicit ON UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable()->change();
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->foreignId('student_profile_id')->nullable()->after('enrollment_id')
                ->unique()->constrained('student_profiles')->cascadeOnDelete();
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->dateTime('link_sent_at')->nullable();
            $table->dateTime('link_failed_at')->nullable();
        });

        DB::statement(
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_has_an_owner '
            .'CHECK (enrollment_id IS NOT NULL OR student_profile_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        // A ticket that exists only for a freshman profile has no enrollment to fall back to.
        DB::table('queue_tickets')->whereNull('enrollment_id')->delete();

        DB::statement('ALTER TABLE queue_tickets DROP CONSTRAINT queue_tickets_has_an_owner');

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropForeign(['student_profile_id']);
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropUnique(['student_profile_id']);
            $table->dropUnique(['public_token_hash']);
            $table->dropColumn(['student_profile_id', 'public_token_hash', 'link_sent_at', 'link_failed_at']);
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable(false)->change();
        });
    }
};

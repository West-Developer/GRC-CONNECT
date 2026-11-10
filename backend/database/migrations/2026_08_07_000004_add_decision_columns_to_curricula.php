<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the new Draft -> PendingDeanReview -> PendingExecutiveReview ->
 * Active approval chain (see `App\Domain\Curriculum\CurriculumTransitionRules`).
 * `decided_by`/`decided_at` record whoever most recently applied a
 * transition (submit, approve, or return); `last_decision_reason` holds the
 * most recent RETURN reason only — it is not cleared on approve, only
 * overwritten by the next return, so a chair can always see why their
 * curriculum was last sent back even after resubmitting once more without
 * yet hearing back again. All three are nullable: a never-submitted Draft
 * has none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricula', function (Blueprint $table): void {
            $table->foreignId('decided_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('last_decision_reason')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('curricula', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['decided_at', 'last_decision_reason']);
        });
    }
};

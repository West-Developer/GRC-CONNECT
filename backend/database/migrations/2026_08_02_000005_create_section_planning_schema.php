<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_term_section_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->string('college');
            $table->unsignedTinyInteger('year_level');
            $table->unsignedSmallInteger('section_count')->default(0);
            $table->string('status')->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['academic_term_id', 'curriculum_id', 'college', 'year_level'],
                'section_plans_term_curriculum_college_year_unique',
            );
        });

        Schema::table('sections', function (Blueprint $table): void {
            $table->foreignId('section_plan_id')
                ->nullable()
                ->after('academic_term_id')
                ->constrained('academic_term_section_plans')
                ->nullOnDelete();
            $table->string('modality')->nullable()->after('room');
        });

        Schema::table('schedule_proposals', function (Blueprint $table): void {
            $table->string('college')->nullable()->after('academic_term_id');
            $table->foreignId('section_plan_id')
                ->nullable()
                ->after('college')
                ->constrained('academic_term_section_plans')
                ->nullOnDelete();
            $table->index(
                ['academic_term_id', 'college', 'status'],
                'schedule_proposals_term_college_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('schedule_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('section_plan_id');
        });

        // `schedule_proposals_term_college_status_index` leads with
        // `academic_term_id`, so MariaDB adopted it as the supporting index
        // for the pre-existing `schedule_proposals_academic_term_id_foreign`
        // constraint and discarded that constraint's own auto-created index.
        // Dropping the composite first therefore fails with errno 1553
        // ("needed in a foreign key constraint") and made `migrate:rollback`
        // impossible. Give the constraint its dedicated index back — the
        // state it was in before this migration ran — and only then remove
        // the composite.
        Schema::table('schedule_proposals', function (Blueprint $table): void {
            $table->index('academic_term_id', 'schedule_proposals_academic_term_id_foreign');
        });

        Schema::table('schedule_proposals', function (Blueprint $table): void {
            $table->dropIndex('schedule_proposals_term_college_status_index');
            $table->dropColumn('college');
        });

        Schema::table('sections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('section_plan_id');
            $table->dropColumn('modality');
        });

        Schema::dropIfExists('academic_term_section_plans');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Admission requirements checklist (stakeholder Doc 14, ADR 0037).
 *
 * `admission_requirement_types` is the shared catalogue of documents a student
 * brings, in three categories: Freshman, Transferee, and Additional (needed by
 * everyone). The stakeholder's list is seeded here exactly, marked `is_system`;
 * Admission Staff can append more (never edit or remove the seeded ones).
 * `student_admission_requirements` records, per student and requirement, that
 * it was submitted and when. A missing row means "not submitted".
 *
 * `dateTime` columns on purpose: MariaDB's legacy `explicit_defaults_for_timestamp
 * = 0` would give a TIMESTAMP column an implicit `ON UPDATE CURRENT_TIMESTAMP`.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const SEED = [
        'freshman' => [
            'Form 137',
            'Form 138',
            'Good Moral Character',
            'Certificate of Ratings',
        ],
        'transferee' => [
            'Certificate of Grades',
            'TOR (Original copy for GRC)',
            'Honorable Dismissal (Original)',
            'Good Moral Character (Original)',
        ],
        'additional' => [
            '2 Pcs 2x2 Picture (White background & nametag)',
            '2 Pcs 1x1 Picture (White background)',
            'Original Birth Certificate (PSA)',
            'Original Marriage Certificate (PSA if Married)',
            '(3PCS) LONG BROWN EXPANDED ENVELOPE',
            'Latest Chest X-ray with normal result',
        ],
    ];

    public function up(): void
    {
        Schema::create('admission_requirement_types', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20);
            $table->string('name', 160);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->nullable();

            $table->unique(['category', 'name'], 'admission_requirement_types_category_name_unique');
        });

        Schema::create('student_admission_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('requirement_type_id')->constrained('admission_requirement_types')->cascadeOnDelete();
            $table->boolean('is_submitted')->default(false);
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['student_profile_id', 'requirement_type_id'], 'student_admission_requirements_pair_unique');
        });

        $now = now()->format('Y-m-d H:i:s');
        foreach (self::SEED as $category => $names) {
            foreach ($names as $index => $name) {
                DB::table('admission_requirement_types')->insert([
                    'category' => $category,
                    'name' => $name,
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                    'is_system' => true,
                    'created_by' => null,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_admission_requirements');
        Schema::dropIfExists('admission_requirement_types');
    }
};

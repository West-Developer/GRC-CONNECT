<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.3 generated enrollment artifacts (renamed to COR by the later
 * reversible conversion migration).
 *
 * Two unique constraints, both required by the PRD: one document per type per
 * enrollment — which is what makes FR-FIN-009's "generating twice must not
 * produce a duplicate document" enforceable in storage — and document numbers
 * unique within their type.
 *
 * `document_type` is deliberately a plain string. The 2026-08-25 conversion
 * migrates its legacy value to the single canonical `cor` artifact and adds
 * the immutable printable snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('document_number');
            $table->string('storage_path')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(
                ['enrollment_id', 'document_type'],
                'enrollment_documents_unique_type_per_enrollment',
            );
            $table->unique(
                ['document_type', 'document_number'],
                'enrollment_documents_unique_number_per_type',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_documents');
    }
};

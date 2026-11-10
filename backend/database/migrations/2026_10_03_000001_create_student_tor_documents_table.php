<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transferee's or returnee's Transcript of Records, uploaded as a file so the
 * Program Chair can read it while mapping their previous subjects to the GRC
 * curriculum (credit mapping, ADR 0026). Only the metadata lives here; the file
 * itself is on the private `local` disk (never a public URL) and is served only
 * through an authorized endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_tor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('stored_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->index(['student_id', 'created_at'], 'student_tor_documents_student_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_tor_documents');
    }
};

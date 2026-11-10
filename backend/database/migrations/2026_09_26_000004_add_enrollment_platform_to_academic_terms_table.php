<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Certificate of Registration prints a "Platform" (Online or Face-to-Face)
 * but nothing ever supplied it: students have no such field. The Registrar
 * Head now sets one value per term for everyone who enrolls in it (stakeholder
 * Doc 14); the COR builder reads it. Null means "not set yet", which the COR
 * keeps showing as "Not provided". A plain nullable string, validated against
 * `App\Domain\Enrollment\EnrollmentPlatform` in the application, like the other
 * short vocabularies on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->string('enrollment_platform', 20)->nullable()->after('add_drop_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropColumn('enrollment_platform');
        });
    }
};

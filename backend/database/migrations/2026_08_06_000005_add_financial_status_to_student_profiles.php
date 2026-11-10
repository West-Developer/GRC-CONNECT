<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a student is a Scholar or a Payee — informational only, set at
 * admission provisioning. Nullable: most existing/seeded students never set
 * it. See `App\Domain\Identity\FinancialStatus`'s docblock for why this
 * never touches fee computation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('financial_status')->nullable()->after('academic_standing');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('financial_status');
        });
    }
};

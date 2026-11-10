<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->boolean('is_demo_account')->default(false)->after('financial_status');
        });

        DB::table('student_profiles as student_profiles')
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->where('users.email', 'like', 'student%.seed@grc.test')
            ->update(['student_profiles.is_demo_account' => true]);
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn('is_demo_account');
        });
    }
};

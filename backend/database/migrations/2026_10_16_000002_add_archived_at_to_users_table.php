<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An archived account (ADR 0054): IT Control retires a Student or Faculty account that has records and so
 * cannot be deleted. The account is disabled (it cannot sign in or be set up) and leaves the default lists, and
 * IT Control can restore it. Nothing else about the person's records changes. Nullable: no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};

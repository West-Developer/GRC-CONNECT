<?php

use App\Domain\Identity\PersonName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Users written after the 2026-08-26 split-name migration by a path that only set the display
 * `name` (the demo seeders, one-off scripts) have no first/last name, and the student profile and
 * account-list API contracts require both. The User model now derives them on save; this fills the
 * rows already in the table. Only rows with no parts at all are touched, and `name` is never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where(function ($query): void {
                $query->whereNull('first_name')->orWhere('first_name', '');
            })
            ->where(function ($query): void {
                $query->whereNull('last_name')->orWhere('last_name', '');
            })
            ->where('name', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function (Collection $users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update(PersonName::split($user->name));
                }
            });
    }

    public function down(): void
    {
        // Data backfill only: the derived parts are indistinguishable from supplied ones, so there is
        // nothing safe to undo.
    }
};

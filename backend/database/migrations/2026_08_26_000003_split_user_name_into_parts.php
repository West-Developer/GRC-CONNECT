<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('middle_initial', 10)->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_initial');
            $table->string('suffix', 20)->nullable()->after('last_name');
        });

        // Best-effort structured metadata parsed from the existing `name`
        // string. `name` itself is never rewritten here — it stays the
        // authoritative display value for every existing account, so this
        // backfill carries zero display-regression risk. chunkById (not
        // chunk()/each(), which page by OFFSET): every processed row leaves
        // the `first_name IS NULL` filter this same query re-runs each
        // chunk, so offset-based paging would silently skip rows once the
        // table exceeds one chunk (the exact bug already hit and fixed in
        // the 2026_08_25 COM/COR migration).
        DB::table('users')
            ->whereNull('first_name')
            ->orderBy('id')
            ->chunkById(500, function (Collection $users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(self::splitName($user->name));
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'middle_initial', 'last_name', 'suffix']);
        });
    }

    /**
     * @return array{first_name: string, middle_initial: ?string, last_name: string, suffix: ?string}
     */
    private static function splitName(string $fullName): array
    {
        $name = trim($fullName);

        // Faculty CSV import artifact: "SURNAME,GIVEN NAME(annotation)".
        if (str_contains($name, ',')) {
            $parts = array_map('trim', explode(',', $name, 2));
            $last = $parts[0];
            $first = trim((string) preg_replace('/\([^)]*\)/', '', $parts[1]));

            return [
                'first_name' => $first !== '' ? $first : $last,
                'middle_initial' => null,
                'last_name' => $last !== '' ? $last : $first,
                'suffix' => null,
            ];
        }

        $tokens = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

        $suffix = null;
        if (count($tokens) > 1 && preg_match('/^(Jr\.?|Sr\.?|II|III|IV|V)$/i', (string) end($tokens))) {
            $suffix = array_pop($tokens);
        }

        if (count($tokens) === 1) {
            return [
                'first_name' => $tokens[0],
                'middle_initial' => null,
                'last_name' => $tokens[0],
                'suffix' => $suffix,
            ];
        }

        // count($tokens) >= 2 here (the === 1 case already returned), so
        // array_shift/array_pop can never actually return null — PHPStan's
        // stubs just can't see that from the preceding count() check.
        $first = array_shift($tokens) ?? '';
        $last = array_pop($tokens) ?? $first;
        $middleInitial = $tokens !== [] ? mb_strtoupper(mb_substr($tokens[0], 0, 1)) : null;

        return [
            'first_name' => $first,
            'middle_initial' => $middleInitial,
            'last_name' => $last,
            'suffix' => $suffix,
        ];
    }
};

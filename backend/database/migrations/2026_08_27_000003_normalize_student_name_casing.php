<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One-time casing fix so every student record — however Admission
        // originally typed it (ALL CAPS, all lowercase, mixed) — displays
        // and edits the same way as a freshly provisioned one. Scoped to
        // role=student only: this is a Student Records/Admission concern,
        // not a blanket rewrite of every account in the shared `users`
        // table. Self-contained (no App\ class imports) to match this
        // repo's existing migration convention (see
        // 2026_08_26_000003_split_user_name_into_parts.php) so this
        // migration's behavior never drifts if the production PersonName
        // class changes later.
        DB::table('users')
            ->where('role', 'student')
            ->orderBy('id')
            ->chunkById(500, function (Collection $users): void {
                foreach ($users as $user) {
                    $firstName = self::normalizeNamePart($user->first_name);
                    $middleInitial = self::normalizeNamePart($user->middle_initial);
                    $lastName = self::normalizeNamePart($user->last_name);
                    $suffix = self::normalizeSuffix($user->suffix);

                    DB::table('users')->where('id', $user->id)->update([
                        'first_name' => $firstName,
                        'middle_initial' => $middleInitial,
                        'last_name' => $lastName,
                        'suffix' => $suffix,
                        'name' => self::composeName($firstName, $middleInitial, $lastName, $suffix),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Casing normalization is lossy (the original casing is not
        // recorded anywhere), so this migration cannot be reversed.
    }

    private static function normalizeNamePart(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\p{L}[\p{L}\p{Mn}]*/u',
            static fn (array $match): string => mb_strtoupper(mb_substr($match[0], 0, 1)).mb_strtolower(mb_substr($match[0], 1)),
            $trimmed,
        );
    }

    private static function normalizeSuffix(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if (preg_match('/^(I|II|III|IV|V)$/i', $trimmed) === 1) {
            return mb_strtoupper($trimmed);
        }

        return self::normalizeNamePart($trimmed);
    }

    private static function composeName(?string $firstName, ?string $middleInitial, ?string $lastName, ?string $suffix): string
    {
        $middle = trim((string) $middleInitial);
        $parts = [
            trim((string) $firstName),
            $middle !== '' ? rtrim($middle, '.').'.' : null,
            trim((string) $lastName),
            trim((string) $suffix) !== '' ? trim((string) $suffix) : null,
        ];

        return implode(' ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
    }
};

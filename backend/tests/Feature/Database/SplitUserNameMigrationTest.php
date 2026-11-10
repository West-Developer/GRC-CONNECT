<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class SplitUserNameMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    public function test_the_migration_backfills_first_middle_last_and_suffix_from_existing_names(): void
    {
        $this->rollbackThrough('2026_08_26_000003_split_user_name_into_parts');
        self::assertFalse(Schema::hasColumn('users', 'first_name'));

        $cases = [
            'simple two-part' => 'Aurora Lopez',
            'with middle name' => 'Aurora Santos Lopez',
            'with suffix' => 'Juan Dela Cruz Jr.',
            'with middle and suffix' => 'Juan Miguel Dela Cruz III',
            'single token' => 'PALADA',
            'csv surname,given' => 'TANANGONAN,EVELYN',
            'csv with annotation' => 'LAYOS,JOLAND(NEW2)',
        ];
        $ids = [];
        foreach ($cases as $key => $name) {
            $ids[$key] = DB::table('users')->insertGetId([
                'name' => $name,
                'email' => $key.'@grc.test',
                'password' => 'irrelevant-hash',
                'role' => 'faculty',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('migrate')->assertExitCode(0);

        $row = fn (int $id) => DB::table('users')->where('id', $id)->first();

        $simple = $row($ids['simple two-part']);
        self::assertSame('Aurora', $simple->first_name);
        self::assertNull($simple->middle_initial);
        self::assertSame('Lopez', $simple->last_name);
        self::assertNull($simple->suffix);
        self::assertSame('Aurora Lopez', $simple->name, 'name must be left untouched by the backfill');

        $withMiddle = $row($ids['with middle name']);
        self::assertSame('Aurora', $withMiddle->first_name);
        self::assertSame('S', $withMiddle->middle_initial);
        self::assertSame('Lopez', $withMiddle->last_name);

        $withSuffix = $row($ids['with suffix']);
        self::assertSame('Juan', $withSuffix->first_name);
        self::assertSame('Cruz', $withSuffix->last_name);
        self::assertSame('Jr.', $withSuffix->suffix);

        $withBoth = $row($ids['with middle and suffix']);
        self::assertSame('Juan', $withBoth->first_name);
        self::assertSame('M', $withBoth->middle_initial);
        self::assertSame('Cruz', $withBoth->last_name);
        self::assertSame('III', $withBoth->suffix);

        $single = $row($ids['single token']);
        self::assertSame('PALADA', $single->first_name);
        self::assertSame('PALADA', $single->last_name);

        $csv = $row($ids['csv surname,given']);
        self::assertSame('EVELYN', $csv->first_name);
        self::assertSame('TANANGONAN', $csv->last_name);

        $csvAnnotated = $row($ids['csv with annotation']);
        self::assertSame('JOLAND', $csvAnnotated->first_name);
        self::assertSame('LAYOS', $csvAnnotated->last_name);
    }
}

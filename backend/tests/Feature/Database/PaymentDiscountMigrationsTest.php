<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class PaymentDiscountMigrationsTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    private const SETTINGS_MIGRATION = '2026_10_12_000001_create_payment_discount_settings_table';

    public function test_the_settings_table_and_the_profile_column_exist_and_roll_back(): void
    {
        $this->assertTrue(Schema::hasTable('payment_discount_settings'));
        foreach (['percentage', 'valid_from', 'valid_until', 'updated_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('payment_discount_settings', $column), "Missing {$column}.");
        }
        $this->assertTrue(Schema::hasColumn('student_profiles', 'full_payment_discount_percentage'));

        // The profile-column migration is newer, so rolling back through the settings migration undoes both.
        $this->rollbackThrough(self::SETTINGS_MIGRATION);

        $this->assertFalse(Schema::hasTable('payment_discount_settings'));
        $this->assertFalse(Schema::hasColumn('student_profiles', 'full_payment_discount_percentage'));
    }
}

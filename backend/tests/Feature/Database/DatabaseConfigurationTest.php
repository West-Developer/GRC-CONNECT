<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseConfigurationTest extends TestCase
{
    public function test_default_connection_targets_mariadb_with_the_expected_collation(): void
    {
        $connection = DB::connection();

        $this->assertSame('mariadb', $connection->getConfig('driver'));
        $this->assertSame('utf8mb4', $connection->getConfig('charset'));
        $this->assertSame('utf8mb4_unicode_ci', $connection->getConfig('collation'));
        $this->assertTrue($connection->getConfig('strict'));
    }

    public function test_live_connection_reaches_a_real_mariadb_10_4_server(): void
    {
        $version = DB::selectOne('SELECT VERSION() as v')->v;

        $this->assertStringStartsWith('10.4', $version);
    }

    public function test_connection_variables_match_the_expected_charset_and_collation(): void
    {
        $charset = DB::selectOne("SHOW VARIABLES LIKE 'character_set_connection'")->Value;
        $collation = DB::selectOne("SHOW VARIABLES LIKE 'collation_connection'")->Value;

        $this->assertSame('utf8mb4', $charset);
        $this->assertSame('utf8mb4_unicode_ci', $collation);
    }

    public function test_strict_sql_mode_is_enforced_on_this_connection(): void
    {
        $sqlMode = DB::selectOne('SELECT @@SESSION.sql_mode as mode')->mode;

        $this->assertStringContainsString('STRICT_TRANS_TABLES', $sqlMode);
    }

    public function test_migrator_connection_config_is_present_for_local_development(): void
    {
        $migrator = config('database.connections.mariadb_migrator');

        $this->assertNotNull($migrator);
        $this->assertSame('mariadb', $migrator['driver']);
        $this->assertSame('utf8mb4_unicode_ci', $migrator['collation']);
    }
}

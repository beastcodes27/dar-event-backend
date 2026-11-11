<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublisherMigrationRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'billing_recovery', 'database.connections.billing_recovery' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
    }

    public static function existingTables(): array
    {
        return ['fresh database' => [0], 'plans only' => [1], 'plans and subscriptions' => [2], 'all tables already present' => [3]];
    }

    #[DataProvider('existingTables')]
    public function test_resumes_billing_setup_without_losing_existing_plans(int $existingTables): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        $migration = require database_path('migrations/2026_11_11_175020_create_publisher_plans_table.php');
        $migration->up();
        $tables = ['publisher_plans', 'publisher_subscriptions', 'publisher_payments'];
        foreach (array_reverse(array_slice($tables, $existingTables)) as $table) {
            Schema::drop($table);
        }
        if ($existingTables > 0) {
            DB::table('publisher_plans')->insert(['id' => 42, 'name' => 'Existing publisher plan', 'price' => 15000, 'duration_days' => 30]);
        }

        $migration->up();

        $this->assertDatabaseCount('publisher_subscriptions', 0);
        $this->assertDatabaseCount('publisher_payments', 0);
        if ($existingTables > 0) {
            $this->assertDatabaseHas('publisher_plans', ['id' => 42, 'name' => 'Existing publisher plan', 'price' => 15000]);
        } else {
            $this->assertDatabaseCount('publisher_plans', 0);
        }
    }

    public function test_mysql_subscription_dates_have_no_implicit_timestamp_defaults(): void
    {
        config(['database.default' => 'billing_mysql_preview', 'database.connections.billing_mysql_preview' => [
            'driver' => 'mysql', 'database' => 'billing_preview', 'host' => '127.0.0.1', 'prefix' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'version' => '5.7.44',
        ]]);
        $migration = require database_path('migrations/2026_11_11_175020_create_publisher_plans_table.php');

        $queries = DB::connection()->pretend(fn () => $migration->up());

        $subscriptionSql = collect($queries)->first(fn (array $query): bool => str_starts_with($query['query'], 'create table `publisher_subscriptions`'))['query'];
        $this->assertStringContainsString('`starts_at` datetime not null', $subscriptionSql);
        $this->assertStringContainsString('`ends_at` datetime not null', $subscriptionSql);
        $this->assertStringNotContainsString('on update', $subscriptionSql);
    }

    public function test_rejects_an_incompatible_existing_table_without_changing_it(): void
    {
        Schema::create('publisher_plans', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('publisher_plans')->insert(['id' => 42]);
        $migration = require database_path('migrations/2026_11_11_175020_create_publisher_plans_table.php');

        try {
            $migration->up();
            $this->fail('An incompatible billing table must prevent migration completion.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('publisher_plans table has missing billing columns', $exception->getMessage());
        }

        $this->assertDatabaseHas('publisher_plans', ['id' => 42]);
        $this->assertFalse(Schema::hasTable('publisher_subscriptions'));
        $this->assertFalse(Schema::hasTable('publisher_payments'));
    }
}

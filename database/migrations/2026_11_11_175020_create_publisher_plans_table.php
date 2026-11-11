<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $requiredColumns = [
            'publisher_plans' => ['id', 'name', 'description', 'price', 'currency', 'duration_days', 'is_active', 'created_at', 'updated_at'],
            'publisher_subscriptions' => ['id', 'user_id', 'publisher_plan_id', 'status', 'starts_at', 'ends_at', 'created_at', 'updated_at'],
            'publisher_payments' => ['id', 'user_id', 'publisher_plan_id', 'reference', 'amount', 'currency', 'duration_days', 'phone', 'status', 'provider_order_id', 'provider_transaction_id', 'paid_at', 'created_at', 'updated_at'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            if (Schema::hasTable($table) && ! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException("Existing {$table} table has missing billing columns. Reconcile its schema before retrying this migration; no tables have been dropped.");
            }
        }

        if (! Schema::hasTable('publisher_plans')) {
            Schema::create('publisher_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2);
                $table->string('currency', 3)->default('TZS');
                $table->unsignedSmallInteger('duration_days');
                $table->boolean('is_active')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('publisher_subscriptions')) {
            Schema::create('publisher_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->foreignId('publisher_plan_id')->constrained()->restrictOnDelete();
                $table->string('status')->default('active');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->index();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('publisher_payments')) {
            Schema::create('publisher_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('publisher_plan_id')->constrained()->restrictOnDelete();
                $table->uuid('reference')->unique();
                $table->decimal('amount', 10, 2);
                $table->string('currency', 3);
                $table->unsignedSmallInteger('duration_days');
                $table->string('phone', 20);
                $table->string('status')->default('initializing')->index();
                $table->string('provider_order_id', 150)->nullable()->unique();
                $table->string('provider_transaction_id', 150)->nullable()->unique();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_payments');
        Schema::dropIfExists('publisher_subscriptions');
        Schema::dropIfExists('publisher_plans');
    }
};

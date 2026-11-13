<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('phone', 16)->nullable()->unique();
            $table->boolean('is_official')->default(false)->index();
            $table->unsignedTinyInteger('onboarding_step')->default(4);
            $table->dateTime('onboarding_completed_at')->nullable();
        });
        DB::table('users')->update(['onboarding_step' => 8, 'onboarding_completed_at' => now()]);
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Add emails to phone-only accounts before rolling back phone registration.');
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'is_official', 'onboarding_step', 'onboarding_completed_at']);
            $table->string('email')->nullable(false)->change();
        });
    }
};

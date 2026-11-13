<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'auth_session_version')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedInteger('auth_session_version')->default(0));
        }
        if (! Schema::hasTable('account_recoveries')) {
            Schema::create('account_recoveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->char('token_hash', 64)->unique();
                $table->string('lookup_channel', 10);
                $table->char('identifier_hash', 64);
                $table->char('password_fingerprint', 64);
                $table->string('delivery_channel', 10)->nullable();
                $table->char('destination_hash', 64)->nullable();
                $table->string('code_hash')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedTinyInteger('send_count')->default(0);
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('expires_at')->index();
                $table->dateTime('verified_at')->nullable();
                $table->char('reset_token_hash', 64)->nullable();
                $table->dateTime('consumed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_recoveries');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('auth_session_version'));
    }
};

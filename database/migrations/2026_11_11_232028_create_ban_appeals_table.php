<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'ban_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('ban_status', 30)->default('active')->index();
                $table->text('ban_reason')->nullable();
                $table->dateTime('banned_at')->nullable();
                $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('ban_version')->default(0);
            });
        }
        if (! Schema::hasTable('ban_appeals')) {
            Schema::create('ban_appeals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('ban_version');
                $table->text('ban_reason');
                $table->text('message');
                $table->string('status', 20)->default('pending')->index();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('reviewed_at')->nullable();
                $table->text('decision_reason')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'ban_version']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ban_appeals');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('banned_by');
            $table->dropColumn(['ban_status', 'ban_reason', 'banned_at', 'ban_version']);
        });
    }
};

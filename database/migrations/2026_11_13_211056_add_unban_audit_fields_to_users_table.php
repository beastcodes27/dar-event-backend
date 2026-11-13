<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dateTime('unbanned_at')->nullable();
            $table->foreignId('unbanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('unban_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('unbanned_by');
            $table->dropColumn(['unbanned_at', 'unban_reason']);
        });
    }
};

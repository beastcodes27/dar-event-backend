<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_for_everyone_at')->nullable();
            $table->timestamp('sender_deleted_at')->nullable();
            $table->timestamp('recipient_deleted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'deleted_for_everyone_at', 'sender_deleted_at', 'recipient_deleted_at']);
        });
    }
};

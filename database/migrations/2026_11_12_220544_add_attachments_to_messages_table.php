<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('attachment_type', 10)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->unsignedInteger('attachment_duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn(['attachment_type', 'attachment_path', 'attachment_mime', 'attachment_size', 'attachment_duration_ms']));
    }
};

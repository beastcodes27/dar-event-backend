<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('video_source_path')->nullable();
            $table->string('video_stream_path')->nullable();
            $table->string('video_status')->nullable();
            $table->json('video_variants')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['video_source_path', 'video_stream_path', 'video_status', 'video_variants']));
    }
};

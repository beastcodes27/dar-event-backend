<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->index();
            $table->string('location');
            $table->string('venue')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('image_path')->nullable();
            $table->string('video_url')->nullable();
            $table->boolean('has_video')->default(false);
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

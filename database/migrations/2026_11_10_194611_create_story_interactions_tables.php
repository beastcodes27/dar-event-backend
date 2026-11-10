<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['story_views', 'story_likes'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->foreignId('story_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['story_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('story_likes');
        Schema::dropIfExists('story_views');
    }
};

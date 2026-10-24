<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('query');
            $table->string('type')->default('search');
            $table->string('subtitle')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_searches');
    }
};

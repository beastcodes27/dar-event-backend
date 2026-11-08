<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('discoverable')->default(true);
            $table->boolean('allow_messages')->default(true);
            $table->boolean('is_admin')->default(false);
        });
        Schema::table('events', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
        });
        Schema::create('account_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'blocked_user_id']);
        });
        Schema::create('support_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 150);
            $table->text('body');
            $table->string('status')->default('open');
            $table->text('admin_reply')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('account_blocks');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['discoverable', 'allow_messages', 'is_admin']));
    }
};

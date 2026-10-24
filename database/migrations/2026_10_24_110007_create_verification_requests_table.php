<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('account_type');
            $table->string('document_path');
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_reference')->nullable();
            $table->decimal('amount', 8, 2)->default(9.99);
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('reserved', 12, 2)->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->timestamps();
            
            $table->unique('user_id');
            $table->index('balance');
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->enum('type', ['credit', 'debit', 'refund', 'reserve', 'release']);
            $table->string('description');
            $table->string('reference')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->string('payment_method')->nullable();
            $table->json('payment_data')->nullable();
            $table->timestamps();
            
            $table->index(['wallet_id', 'created_at']);
            $table->index('status');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};

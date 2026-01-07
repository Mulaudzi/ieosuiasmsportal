<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('channel', ['sms', 'email']);
            $table->text('content');
            $table->string('subject')->nullable();
            $table->json('variables')->nullable();
            $table->string('category', 100)->default('General');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            
            $table->index(['user_id', 'channel']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};

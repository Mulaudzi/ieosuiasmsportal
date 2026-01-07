<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dlr_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->onDelete('cascade');
            $table->string('external_id')->nullable();
            $table->string('status', 50);
            $table->json('raw_payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            
            $table->index('message_id');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dlr_logs');
    }
};

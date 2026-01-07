<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('recipient');
            $table->enum('channel', ['sms', 'email', 'all'])->default('all');
            $table->text('reason')->nullable();
            $table->string('source', 50)->default('manual');
            $table->foreignId('campaign_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
            
            $table->index(['user_id', 'recipient']);
            $table->index('channel');
            $table->unique(['user_id', 'recipient', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opt_outs');
    }
};

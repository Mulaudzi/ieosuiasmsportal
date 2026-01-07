<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->string('recipient');
            $table->text('content');
            $table->enum('status', [
                'Pending',
                'Queued',
                'Sent',
                'Awaiting DLR',
                'Delivered',
                'Failed',
                'Opted-Out'
            ])->default('Pending');
            $table->string('external_id')->nullable();
            $table->decimal('cost', 10, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('cascade');
            $table->index('status');
            $table->index('recipient');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('channel', ['sms', 'email']);
            $table->enum('status', [
                'Draft', 'Pending', 'Queued', 'Sending', 'Sent', 
                'Completed', 'Failed', 'Cancelled'
            ])->default('Draft');
            $table->text('message')->nullable();
            $table->string('subject')->nullable();
            $table->string('sender_id', 11)->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->foreignId('template_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->decimal('actual_cost', 12, 2)->default(0);
            $table->decimal('reserved_credits', 12, 2)->default(0);
            $table->timestamps();
            
            $table->index(['user_id', 'channel']);
            $table->index('status');
            $table->index('scheduled_at');
            $table->index('created_at');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');
            $table->string('recipient');
            $table->text('content');
            $table->string('subject')->nullable();
            $table->enum('status', [
                'Pending', 'Queued', 'Sent', 'Awaiting DLR', 
                'Delivered', 'Failed', 'Opted-Out', 'Rejected'
            ])->default('Pending');
            $table->string('external_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->decimal('cost', 8, 4)->default(0);
            $table->integer('parts')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index(['campaign_id', 'status']);
            $table->index('external_id');
            $table->index('recipient');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('campaigns');
    }
};

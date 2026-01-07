<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 7)->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->unique(['user_id', 'name']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->json('custom_fields')->nullable();
            $table->boolean('opt_out')->default(false);
            $table->string('source', 50)->default('manual');
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('phone');
            $table->index('email');
            $table->index('opt_out');
        });

        Schema::create('group_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('contact_groups')->onDelete('cascade');
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['group_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_contacts');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('contact_groups');
    }
};

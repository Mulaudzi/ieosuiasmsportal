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
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('name');
        });

        Schema::create('group_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('contact_id');
            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('contact_groups')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->unique(['group_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_contacts');
        Schema::dropIfExists('contact_groups');
    }
};

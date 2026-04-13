<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 120);
            $table->string('source', 120)->nullable();
            $table->string('status', 40)->index();
            $table->string('priority', 40)->default('default');
            $table->string('queue', 120)->nullable();
            $table->string('kafka_topic', 191)->nullable();
            $table->string('kafka_key', 191)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_tasks');
    }
};

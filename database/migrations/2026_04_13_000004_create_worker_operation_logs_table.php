<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_operation_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 120)->index();
            $table->string('status', 40)->default('success')->index();
            $table->string('actor', 191)->nullable()->index();
            $table->string('channel', 40)->default('api');
            $table->uuid('worker_task_id')->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_operation_logs');
    }
};

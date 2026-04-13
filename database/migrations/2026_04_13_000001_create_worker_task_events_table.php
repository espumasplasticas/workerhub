<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_task_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('worker_task_id')->index();
            $table->string('event', 120);
            $table->string('level', 40)->default('info');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_task_events');
    }
};

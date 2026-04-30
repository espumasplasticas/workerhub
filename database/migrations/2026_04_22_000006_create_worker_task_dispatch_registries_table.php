<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_task_dispatch_registries', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('document_id', 191);
            $table->string('event_type', 40);
            $table->string('source', 120)->nullable();
            $table->uuid('task_id')->unique();
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->unique(['entity_type', 'event_type', 'document_id'], 'worker_task_dispatch_reg_unique');
            $table->index(['entity_type', 'event_type', 'entity_id'], 'worker_task_dispatch_reg_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_task_dispatch_registries');
    }
};

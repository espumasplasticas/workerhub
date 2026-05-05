<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siesa_web_services', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('worker_task_id')->nullable()->index();
            $table->string('task_type', 80)->nullable()->index();
            $table->string('document_id')->nullable()->index();
            $table->string('source', 120)->nullable();
            $table->string('import_stage', 80)->nullable()->index();
            $table->json('context')->nullable();
            $table->longText('xml');
            $table->boolean('result')->nullable();
            $table->longText('result_text')->nullable();
            $table->timestamp('ts')->useCurrent();
            $table->timestamp('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siesa_web_services');
    }
};

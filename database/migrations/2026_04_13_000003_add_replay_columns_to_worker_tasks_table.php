<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_tasks', function (Blueprint $table) {
            $table->uuid('parent_task_id')->nullable()->after('id')->index();
            $table->timestamp('replayed_at')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('worker_tasks', function (Blueprint $table) {
            $table->dropColumn(['parent_task_id', 'replayed_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->string('require_task_kind', 40)
                ->nullable()
                ->after('require_task');

            $table->boolean('require_task_completed')
                ->default(false)
                ->after('require_task_kind');
        });
    }

    public function down(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->dropColumn(['require_task_kind', 'require_task_completed']);
        });
    }
};

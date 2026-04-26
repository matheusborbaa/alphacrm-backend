<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {

            $table->dateTime('due_at')
                  ->nullable()
                  ->after('ends_at');

            $table->dateTime('completed_at')
                  ->nullable()
                  ->after('due_at');

            $table->unsignedBigInteger('completed_by')
                  ->nullable()
                  ->after('completed_at');

            $table->string('priority', 10)
                  ->nullable()
                  ->after('completed_by');

            $table->dateTime('reminder_at')
                  ->nullable()
                  ->after('priority');

            $table->index(['user_id', 'due_at'], 'appointments_user_due_idx');
            $table->index(['lead_id', 'due_at'], 'appointments_lead_due_idx');
            $table->index('completed_at', 'appointments_completed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_user_due_idx');
            $table->dropIndex('appointments_lead_due_idx');
            $table->dropIndex('appointments_completed_idx');

            $table->dropColumn([
                'due_at',
                'completed_at',
                'completed_by',
                'priority',
                'reminder_at',
            ]);
        });
    }
};

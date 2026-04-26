<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('temperature', ['frio', 'morno', 'quente'])
                  ->nullable()
                  ->after('sla_status')
                  ->index();

            $table->decimal('value', 12, 2)
                  ->nullable()
                  ->after('temperature');

            $table->timestamp('last_interaction_at')
                  ->nullable()
                  ->after('value');

            $table->timestamp('status_changed_at')
                  ->nullable()
                  ->after('last_interaction_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['temperature']);
            $table->dropColumn(['temperature', 'value', 'last_interaction_at', 'status_changed_at']);
        });
    }
};

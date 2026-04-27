<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {

            $table->unsignedBigInteger('channel_id')->nullable()->after('channel');
            $table->foreign('channel_id')
                  ->references('id')->on('lead_channels')
                  ->nullOnDelete();
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropIndex(['channel_id']);
            $table->dropColumn('channel_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('body');

            $table->index(['conversation_id', 'read_at'], 'chat_messages_conv_read_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_conv_read_idx');
            $table->dropColumn('read_at');
        });
    }
};

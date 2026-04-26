<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('body');
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            $table->foreignId('pinned_by_user_id')
                ->nullable()
                ->after('pinned_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['conversation_id', 'is_pinned'], 'chat_messages_conv_pinned_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_conv_pinned_idx');
            $table->dropConstrainedForeignId('pinned_by_user_id');
            $table->dropColumn(['is_pinned', 'pinned_at']);
        });
    }
};

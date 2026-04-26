<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {

        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
        });

        DB::statement('ALTER TABLE chat_message_attachments MODIFY message_id BIGINT UNSIGNED NULL');

        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->foreign('message_id')
                ->references('id')->on('chat_messages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {

        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
        });

        DB::statement('ALTER TABLE chat_message_attachments MODIFY message_id BIGINT UNSIGNED NOT NULL');

        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->foreign('message_id')
                ->references('id')->on('chat_messages')
                ->cascadeOnDelete();
        });
    }
};

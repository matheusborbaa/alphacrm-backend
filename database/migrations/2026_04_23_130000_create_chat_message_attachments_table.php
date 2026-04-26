<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_message_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('chat_messages')
                ->cascadeOnDelete();

            $table->string('type', 32);

            $table->unsignedBigInteger('attachable_id')->nullable();

            $table->string('storage_path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('uploader_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->json('snapshot')->nullable();

            $table->timestamps();

            $table->index(['message_id', 'id'], 'chat_msg_att_msg_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_attachments');
    }
};

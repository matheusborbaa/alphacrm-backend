<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_a_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_b_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_message_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['user_a_id', 'user_b_id'], 'chat_conversations_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};

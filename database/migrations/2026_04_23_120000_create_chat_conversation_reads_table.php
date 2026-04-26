<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversation_reads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();

            $table->unsignedBigInteger('last_read_message_id')->nullable();

            $table->timestamp('last_read_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'conversation_id'], 'chat_reads_user_conv_unique');

            $table->index(['user_id'], 'chat_reads_user_idx');
        });

        $now = now();
        DB::table('chat_conversations')->orderBy('id')->chunk(500, function ($convs) use ($now) {
            $rows = [];
            foreach ($convs as $c) {
                $maxId = DB::table('chat_messages')
                    ->where('conversation_id', $c->id)
                    ->max('id');
                if (!$maxId) continue;

                foreach ([$c->user_a_id, $c->user_b_id] as $userId) {
                    if (!$userId) continue;
                    $rows[] = [
                        'user_id'              => $userId,
                        'conversation_id'      => $c->id,
                        'last_read_message_id' => $maxId,
                        'last_read_at'         => $now,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ];
                }
            }
            if (!empty($rows)) {

                DB::table('chat_conversation_reads')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversation_reads');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.7a — Comentários por comissão.
 *
 * Pedido da Marcia: "corretor pode ver as em processamento e adicionar
 * comentários por fatura caso discorde". Gestor também pode responder.
 * Thread simples (sem reply aninhado) — ordem cronológica basta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commission_id');
            $table->unsignedBigInteger('user_id');      // quem comentou
            $table->text('body');
            $table->timestamps();

            $table->foreign('commission_id')->references('id')->on('commissions')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['commission_id', 'created_at'], 'comm_comments_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_comments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de e-mails enviados pelo sistema.
 *
 * Registra cada envio (sucesso ou falha) para auditoria admin:
 *   - welcome        : boas-vindas a novo usuário
 *   - reset          : redefinição de senha solicitada pelo próprio usuário
 *   - invite         : convite/redefinição disparada por admin (send-invite)
 *   - test           : e-mail de teste do SMTP
 *   - other          : qualquer outro Mailable
 *
 * triggered_by_user_id = usuário admin/logado que causou o envio (nullable
 * pros casos em que é esqueci-minha-senha anônimo).
 * related_user_id      = usuário-alvo do e-mail (quando aplicável).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_email', 255);
            $table->string('to_name', 255)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('mail_class', 255)->nullable();
            $table->string('type', 40)->default('other');
            $table->string('status', 20)->default('sent'); // sent | failed
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->unsignedBigInteger('related_user_id')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('status');
            $table->index('type');
            $table->index('to_email');
            $table->foreign('triggered_by_user_id')
                  ->references('id')->on('users')->nullOnDelete();
            $table->foreign('related_user_id')
                  ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};

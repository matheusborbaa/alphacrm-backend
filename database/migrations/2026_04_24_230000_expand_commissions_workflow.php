<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 3.7a — State machine completa de Commission.
 *
 * Fluxo novo (vs. só pending/partial/paid):
 *
 *   draft → pending → approved → paid
 *                                partial → paid
 *       → cancelled (em qualquer ponto antes de paid)
 *
 *   - draft:     criada automaticamente pelo LeadObserver quando lead vira
 *                "Vendido". Não contabiliza ainda. Gestor precisa confirmar.
 *   - pending:   gestor confirmou a venda (gerou a fatura). Aguarda revisão.
 *   - approved:  gestor revisou %/valor finais. Aguarda pagamento.
 *   - partial:   pagamento parcial recebido.
 *   - paid:      quitada.
 *   - cancelled: venda desfeita ou comissão anulada.
 *
 * Campos novos:
 *   - approved_at / approved_by    — quem revisou e quando
 *   - cancelled_at / cancelled_by / cancel_reason
 *   - payment_receipt_path         — comprovante opcional (PDF/imagem)
 *   - notes                        — observações do gestor sobre a comissão
 */
return new class extends Migration
{
    public function up(): void
    {
        // Expande o enum. Comissões antigas (pending/paid) seguem válidas.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `commissions` MODIFY `status` ENUM('draft', 'pending', 'approved', 'partial', 'paid', 'cancelled') NOT NULL DEFAULT 'draft'");
        }

        Schema::table('commissions', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('expected_payment_date');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');

            $table->timestamp('cancelled_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');

            $table->string('payment_receipt_path', 500)->nullable()->after('cancel_reason');
            $table->text('notes')->nullable()->after('payment_receipt_path');

            $table->index('status', 'commissions_status_idx');
            $table->index(['user_id', 'status'], 'commissions_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropIndex('commissions_status_idx');
            $table->dropIndex('commissions_user_status_idx');
            $table->dropColumn([
                'approved_at', 'approved_by',
                'cancelled_at', 'cancelled_by', 'cancel_reason',
                'payment_receipt_path', 'notes',
            ]);
        });

        if (DB::getDriverName() !== 'sqlite') {
            // Reverte status "novos" pra pending antes de reduzir o enum.
            DB::table('commissions')->whereIn('status', ['draft', 'approved', 'cancelled'])
                ->update(['status' => 'pending']);
            DB::statement("ALTER TABLE `commissions` MODIFY `status` ENUM('pending', 'partial', 'paid') NOT NULL DEFAULT 'pending'");
        }
    }
};

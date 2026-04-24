<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 3.5b — Bloco Financeiro: lista "Minhas Próximas Comissões".
 *
 * Precisa de:
 *  - expected_payment_date (date)  — quando a comissão é esperada pra pagamento.
 *    Antes não havia esse conceito: só 'paid_at' (quando de fato pagou).
 *    Sem expected_date a lista não tem como ordenar/agrupar "próximas".
 *
 *  - status enum estendido pra incluir 'partial' — pagamento parcial.
 *    O mockup da pág 6 prevê 3 tarjas: Pago parcialmente (amarelo),
 *    Pendente (vermelho), Pago (verde). Antes só havia 'pending'/'paid'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->date('expected_payment_date')->nullable()->after('paid_at');
        });

        // SQLite (ambiente de teste local) não aceita MODIFY de ENUM.
        // Mesmo no MySQL, usamos raw pra suportar a expansão do enum.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `commissions` MODIFY `status` ENUM('pending', 'partial', 'paid') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        // Reverte valores 'partial' pra 'pending' antes de reduzir o enum,
        // senão MySQL rejeita o ALTER por perda de dados.
        if (DB::getDriverName() !== 'sqlite') {
            DB::table('commissions')->where('status', 'partial')->update(['status' => 'pending']);
            DB::statement("ALTER TABLE `commissions` MODIFY `status` ENUM('pending', 'paid') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn('expected_payment_date');
        });
    }
};

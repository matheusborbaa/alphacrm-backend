<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.7a — Base do módulo financeiro futuro.
 *
 * Registra TODAS as entradas e saídas financeiras da imobiliária. Hoje
 * alimentado só por eventos de Commission (venda confirmada = entrada;
 * comissão paga = saída). No futuro pode receber RH, contas a pagar,
 * fornecedores, etc.
 *
 * É uma tabela append-only — nunca editamos uma entry; se errou, criamos
 * uma entry de estorno (reference vinculada). Isso mantém o histórico
 * contábil intocável.
 *
 * Polimórfico via (reference_type, reference_id) pra permitir diferentes
 * origens (Commission hoje, Expense/Payroll no futuro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_entries', function (Blueprint $table) {
            $table->id();

            // 'in' = entrada (dinheiro chegando)
            // 'out' = saída (dinheiro saindo)
            $table->enum('direction', ['in', 'out']);

            // Categoria alta — útil pra filtros agregados em relatórios.
            // Ex.: 'sale' (venda concluída), 'commission' (comissão paga ao
            // corretor), 'refund' (estorno), 'other' (RH/fornecedor etc no futuro).
            $table->string('category', 32)->default('other');

            // Quanto — sempre positivo. Direction é quem dá o sinal.
            $table->decimal('amount', 14, 2);

            // Quando foi reconhecida (pode diferir de created_at).
            $table->date('entry_date');

            // Polimórfico — quem originou o lançamento.
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Audit trail.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('description', 500)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

            $table->index('entry_date',   'fin_entries_date_idx');
            $table->index('category',     'fin_entries_category_idx');
            $table->index(['reference_type', 'reference_id'], 'fin_entries_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
    }
};

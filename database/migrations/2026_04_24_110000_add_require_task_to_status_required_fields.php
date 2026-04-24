<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.7 — Regra "exige tarefa registrada".
 *
 * Uma regra pode continuar sendo um campo (lead_column ou custom_field_id)
 * como antes, OU agora pode marcar require_task=true sem nenhum campo.
 * O validator trata isso como: lead precisa ter pelo menos 1 appointment
 * do tipo 'task' antes de avançar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->boolean('require_task')->default(false)->after('custom_field_id');
        });
    }

    public function down(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->dropColumn('require_task');
        });
    }
};

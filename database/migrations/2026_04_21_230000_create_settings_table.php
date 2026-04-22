<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de configurações globais do sistema (key/value).
 *
 * Modelo key/value JSON — favorece evolução sem migrações novas pra cada
 * flag. Todo valor é serializado como JSON pra poder guardar bool, int,
 * string, array ou objeto.
 *
 * Chaves conhecidas hoje:
 *   - watermark_enabled (bool) : liga/desliga a marca d'água PII nas páginas
 *                                autenticadas. Alterável só por admin.
 *
 * Lê/escreve via App\Models\Setting::get() / Setting::set().
 *
 * Acesso: leitura pública (dentro do auth) pra páginas saberem se devem
 * mostrar a marca d'água; escrita restrita a role='admin' no controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

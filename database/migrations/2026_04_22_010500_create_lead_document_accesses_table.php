<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria de acesso a documentos sensíveis.
 *
 * Cada download (e futuramente preview) gera uma row aqui com:
 *   - quem (user_id)
 *   - de onde (ip_address + geolocalização resolvida via ip-api.com)
 *   - quando (accessed_at)
 *   - com qual navegador/app (user_agent)
 *   - ação ("download" / "view" — aberto pra extensão)
 *
 * Motivação (LGPD): em caso de vazamento, a empresa precisa conseguir
 * responder "quem teve acesso a esse documento, quando e de onde". Hoje
 * já temos marca d'água no PDF + histórico no lead, isso fecha a malha.
 *
 * Geo é "best effort" — se a API externa falhar, armazenamos IP + null
 * nos campos de localização e seguimos. O log nunca pode bloquear o
 * download em si.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_document_accesses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_document_id')
                  ->constrained('lead_documents')
                  ->cascadeOnDelete();

            // Também guardamos o lead_id direto pra facilitar queries
            // ("quem acessou qualquer doc desse lead") sem join.
            $table->foreignId('lead_id')
                  ->constrained('leads')
                  ->cascadeOnDelete();

            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Ação: hoje só 'download'; deixamos aberto pra 'view' / 'preview' no futuro.
            $table->string('action', 20)->default('download');

            // Rede + cliente
            $table->string('ip_address', 45)->nullable(); // ipv6-safe
            $table->string('user_agent', 500)->nullable();

            // Geolocalização resolvida (best effort)
            $table->string('country', 80)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('isp', 200)->nullable();
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lon', 10, 6)->nullable();

            $table->timestamp('accessed_at')->useCurrent();

            // Índices pros usos esperados
            $table->index(['lead_document_id', 'accessed_at']);
            $table->index(['lead_id', 'accessed_at']);
            $table->index(['user_id', 'accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_document_accesses');
    }
};

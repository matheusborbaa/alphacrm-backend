<?php

use Illuminate\Database\Migrations\Migration;

/**
 * NOOP — esta migration foi descartada antes de rodar em produção.
 * Razão: o suporte a "documento obrigatório" já é coberto pelos campos
 * personalizados tipo `file` (CustomField type='file'). Basta criar
 * uma regra de obrigatoriedade apontando pra esse custom field.
 *
 * Mantida vazia pra preservar o número de versão.
 */
return new class extends Migration {
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_folders', function (Blueprint $table) {

            $table->foreignId('empreendimento_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('empreendimentos')
                ->nullOnDelete();

            $table->boolean('is_system')
                ->default(false)
                ->after('description');

            $table->index('empreendimento_id');
            $table->index('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('media_folders', function (Blueprint $table) {
            $table->dropForeign(['empreendimento_id']);
            $table->dropIndex(['empreendimento_id']);
            $table->dropIndex(['is_system']);
            $table->dropColumn(['empreendimento_id', 'is_system']);
        });
    }
};

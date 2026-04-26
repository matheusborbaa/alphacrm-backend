<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empreendimento_field_definitions', function (Blueprint $table) {
            $table->json('options')->nullable()->after('group');
            $table->boolean('required')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('empreendimento_field_definitions', function (Blueprint $table) {
            $table->dropColumn(['options', 'required']);
        });
    }
};

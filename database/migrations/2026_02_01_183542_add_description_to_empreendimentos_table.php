<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('empreendimentos', function (Blueprint $table) {
            $table->longText('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('empreendimentos', function (Blueprint $table) {

        });
    }
};

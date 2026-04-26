<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up()
{
    Schema::table('leads', function (Blueprint $table) {
        $table->foreignId('lead_substatus_id')
            ->nullable()
            ->constrained('lead_substatus')
            ->nullOnDelete();
    });
}

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {

        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

   public function up()
{
    Schema::create('lead_substatus', function (Blueprint $table) {
        $table->id();
        $table->foreignId('lead_status_id')
            ->constrained('lead_status')
            ->cascadeOnDelete();

        $table->string('name');
        $table->integer('order')->default(0);
        $table->timestamps();
    });
}

    public function down(): void
    {
        Schema::dropIfExists('lead_substatus');
    }
};

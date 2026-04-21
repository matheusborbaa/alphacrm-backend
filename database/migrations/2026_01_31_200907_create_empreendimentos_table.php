<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('empreendimentos', function (Blueprint $table) {
    $table->id();

    $table->string('name');
    $table->string('code')->unique();
    $table->boolean('active')->default(true);

    $table->decimal('commission_percentage', 5, 2)->default(5);
    $table->decimal('average_sale_value', 12, 2)->nullable();

    $table->date('starts_at')->nullable();
    $table->date('ends_at')->nullable();

    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empreendimentos');
    }
};

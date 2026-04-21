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

        Schema::create('commissions', function (Blueprint $table) {
    $table->id();

    $table->unsignedBigInteger('lead_id');
    $table->unsignedBigInteger('user_id'); // corretor
    $table->decimal('sale_value', 12, 2);
    $table->decimal('commission_percentage', 5, 2);
    $table->decimal('commission_value', 12, 2);

    $table->enum('status', ['pending', 'paid'])->default('pending');
    $table->date('paid_at')->nullable();

    $table->timestamps();

    $table->foreign('lead_id')->references('id')->on('leads');
    $table->foreign('user_id')->references('id')->on('users');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};

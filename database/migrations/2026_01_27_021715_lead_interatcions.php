<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('lead_interactions', function (Blueprint $table) {
    $table->id();

    $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    $table->enum('type', ['whatsapp', 'call', 'email', 'visit']);
    $table->text('note')->nullable();

    $table->timestamps();
});
    }

    public function down(): void
    {

    }
};

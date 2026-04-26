<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
    $table->id();

    $table->string('event');
    $table->string('entity_type');
    $table->unsignedBigInteger('entity_id');

    $table->unsignedBigInteger('user_id')->nullable();
    $table->json('old_values')->nullable();
    $table->json('new_values')->nullable();
    $table->string('source')->nullable();

    $table->timestamps();
});

    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};

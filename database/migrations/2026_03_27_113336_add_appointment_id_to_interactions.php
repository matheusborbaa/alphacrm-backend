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
        Schema::table('lead_interactions', function (Blueprint $table) {
    $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_interactions', function (Blueprint $table) {
            //
        });
    }
};

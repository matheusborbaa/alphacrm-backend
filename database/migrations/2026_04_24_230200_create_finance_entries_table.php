<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_entries', function (Blueprint $table) {
            $table->id();

            $table->enum('direction', ['in', 'out']);

            $table->string('category', 32)->default('other');

            $table->decimal('amount', 14, 2);

            $table->date('entry_date');

            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('description', 500)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

            $table->index('entry_date',   'fin_entries_date_idx');
            $table->index('category',     'fin_entries_category_idx');
            $table->index(['reference_type', 'reference_id'], 'fin_entries_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
    }
};

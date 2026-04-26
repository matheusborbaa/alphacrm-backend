<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->boolean('require_task')->default(false)->after('custom_field_id');
        });
    }

    public function down(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->dropColumn('require_task');
        });
    }
};

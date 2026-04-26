<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->string('color_hex', 7)->nullable()->after('order');
        });

        Schema::table('lead_substatus', function (Blueprint $table) {
            $table->string('color_hex', 7)->nullable()->after('order');
        });

        $palette = [
            '#3B82F6',
            '#10B981',
            '#F59E0B',
            '#EF4444',
            '#8B5CF6',
            '#EC4899',
            '#06B6D4',
            '#84CC16',
            '#F97316',
            '#6366F1',
            '#14B8A6',
            '#A855F7',
        ];

        $statuses = DB::table('lead_status')->orderBy('order')->get(['id']);
        foreach ($statuses as $i => $status) {
            DB::table('lead_status')
                ->where('id', $status->id)
                ->update(['color_hex' => $palette[$i % count($palette)]]);
        }

    }

    public function down(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->dropColumn('color_hex');
        });

        Schema::table('lead_substatus', function (Blueprint $table) {
            $table->dropColumn('color_hex');
        });
    }
};

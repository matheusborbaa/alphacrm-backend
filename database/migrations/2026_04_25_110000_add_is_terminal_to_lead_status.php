<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->boolean('is_terminal')->default(false)->after('color_hex');
        });

        $terminalNames = ['Vendido', 'Perdido', 'Descartado', 'Cancelado'];
        foreach ($terminalNames as $name) {
            DB::table('lead_status')
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->update(['is_terminal' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->dropColumn('is_terminal');
        });
    }
};

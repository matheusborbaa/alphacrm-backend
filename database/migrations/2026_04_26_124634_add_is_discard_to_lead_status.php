<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->boolean('is_discard')->default(false)->after('is_terminal');
        });

        $discardNames = ['Perdido', 'Descartado', 'Cancelado'];
        foreach ($discardNames as $name) {
            DB::table('lead_status')
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->update(['is_discard' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->dropColumn('is_discard');
        });
    }
};

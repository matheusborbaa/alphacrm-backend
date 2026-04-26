<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('phone_normalized', 14)->nullable()->after('phone');
            $table->string('whatsapp_normalized', 14)->nullable()->after('whatsapp');
            $table->index('phone_normalized');
            $table->index('whatsapp_normalized');
        });


        DB::table('leads')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $update = [];

                if (!empty($row->phone)) {
                    $digits = preg_replace('/\D/', '', $row->phone);
                    if (strlen($digits) > 11) {
                        $digits = substr($digits, -11);
                    }
                    $update['phone_normalized'] = $digits ?: null;
                }

                if (!empty($row->whatsapp)) {
                    $digits = preg_replace('/\D/', '', $row->whatsapp);
                    if (strlen($digits) > 11) {
                        $digits = substr($digits, -11);
                    }
                    $update['whatsapp_normalized'] = $digits ?: null;
                }

                if ($update) {
                    DB::table('leads')->where('id', $row->id)->update($update);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['phone_normalized']);
            $table->dropIndex(['whatsapp_normalized']);
            $table->dropColumn(['phone_normalized', 'whatsapp_normalized']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->date('expected_payment_date')->nullable()->after('paid_at');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `commissions` MODIFY `status` ENUM('pending', 'partial', 'paid') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {

        if (DB::getDriverName() !== 'sqlite') {
            DB::table('commissions')->where('status', 'partial')->update(['status' => 'pending']);
            DB::statement("ALTER TABLE `commissions` MODIFY `status` ENUM('pending', 'paid') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn('expected_payment_date');
        });
    }
};

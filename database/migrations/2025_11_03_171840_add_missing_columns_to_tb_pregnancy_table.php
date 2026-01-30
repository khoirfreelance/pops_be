<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_pregnancy', function (Blueprint $table) {
            $table->string('rencana_asi_ekslusif')->nullable();
            $table->string('riwayat_keguguran')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tb_pregnancy', function (Blueprint $table) {
            $table->dropColumn([
                'rencana_asi_ekslusif',
                'riwayat_keguguran',
            ]);
        });
    }
};

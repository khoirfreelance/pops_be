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
        Schema::create('tb_children', function (Blueprint $table) {
            $table->id();
            $table->string('no')->nullable();
            $table->string('nik')->unique();
            $table->string('nama');
            $table->string('jk', 1);
            $table->date('tgl_lahir')->nullable();
            $table->float('bb_lahir')->nullable();
            $table->float('tb_lahir')->nullable();
            $table->string('nama_ortu')->nullable();
            $table->string('prov')->nullable();
            $table->string('kab')->nullable();
            $table->string('kec')->nullable();
            $table->string('puskesmas')->nullable();
            $table->string('desa')->nullable();
            $table->string('posyandu')->nullable();
            $table->string('rt')->nullable();
            $table->string('rw')->nullable();
            $table->text('alamat')->nullable();
            $table->string('usia_saat_ukur')->nullable();
            $table->date('tanggal_pengukuran')->nullable();
            $table->float('berat')->nullable();
            $table->float('tinggi')->nullable();
            $table->float('lila')->nullable();
            $table->string('bb_u')->nullable();
            $table->float('zs_bb_u')->nullable();
            $table->string('tb_u')->nullable();
            $table->float('zs_tb_u')->nullable();
            $table->string('bb_tb')->nullable();
            $table->float('zs_bb_tb')->nullable();
            $table->string('naik_berat_badan')->nullable();
            $table->string('jml_vit_a')->nullable();
            $table->string('kpsp')->nullable();
            $table->string('kia')->nullable();
            $table->string('detail')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_children');
    }
};

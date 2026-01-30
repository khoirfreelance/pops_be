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
        Schema::create('kunjungan_anak', function (Blueprint $table) {
            $table->id();
            $table->string('nik')->nullable();
            $table->string('nama_anak')->nullable();
            $table->enum('jk', ['L', 'P'])->nullable();
            $table->date('tgl_lahir')->nullable();
            $table->float('bb_lahir')->nullable();
            $table->float('tb_lahir')->nullable();
            $table->string('nama_ortu')->nullable();
            $table->string('peran')->nullable(); // ibu/ayah
            $table->string('nik_ortu')->nullable();
            $table->text('alamat')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('kota')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kelurahan')->nullable();
            $table->string('rw')->nullable();
            $table->string('rt')->nullable();
            $table->string('puskesmas')->nullable();
            $table->string('posyandu')->nullable();

            $table->date('tgl_pengukuran')->nullable();
            $table->integer('usia_saat_ukur')->nullable(); // dalam bulan
            $table->float('bb')->nullable();
            $table->float('tb')->nullable();
            $table->float('lila')->nullable();

            // Status & Z-score
            $table->string('bb_u')->nullable();
            $table->float('zs_bb_u')->nullable();
            $table->string('tb_u')->nullable();
            $table->float('zs_tb_u')->nullable();
            $table->string('bb_tb')->nullable();
            $table->float('zs_bb_tb')->nullable();

            $table->boolean('naik_berat_badan')->nullable();

            // Faktor lingkungan
            $table->string('diasuh_oleh')->nullable();
            $table->string('asi')->nullable();
            $table->string('imunisasi')->nullable();
            $table->string('rutin_posyandu')->nullable();
            $table->string('penyakit_bawaan')->nullable();
            $table->string('penyakit_6bulan')->nullable();
            $table->string('terpapar_asap_rokok')->nullable();
            $table->string('penggunaan_jamban_sehat')->nullable();
            $table->string('penggunaan_sab')->nullable();
            $table->string('memiliki_jaminan')->nullable();
            $table->string('kie')->nullable();
            $table->string('mendapatkan_bantuan')->nullable();
            $table->string('kpsp')->nullable();
            $table->string('no_kk')->nullable();
            $table->string('catatan')->nullable();
            $table->string('petugas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kunjungan_anak');
    }
};

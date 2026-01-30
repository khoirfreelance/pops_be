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
        Schema::dropIfExists('pendampingan_anak');
        Schema::create('pendampingan_anak', function (Blueprint $table) {
            $table->id();
            $table->string('petugas')->nullable();
            $table->date('tgl_pendampingan')->nullable();

            // Identitas anak
            $table->string('nama_anak')->nullable();
            $table->string('nik_anak')->nullable();
            $table->string('jk', 10)->nullable();
            $table->date('tgl_lahir')->nullable();
            $table->integer('usia')->nullable();

            // Orang tua
            $table->string('nama_ayah')->nullable();
            $table->string('nik_ayah')->nullable();
            $table->string('pekerjaan_ayah')->nullable();
            $table->integer('usia_ayah')->nullable();

            $table->string('nama_ibu')->nullable();
            $table->string('nik_ibu')->nullable();
            $table->string('pekerjaan_ibu')->nullable();
            $table->integer('usia_ibu')->nullable();

            // Kondisi anak & keluarga
            $table->integer('anak_ke')->nullable();
            $table->string('riwayat_4t')->nullable();
            $table->string('riwayat_kb')->nullable();
            $table->string('alat_kontrasepsi')->nullable();

            // Wilayah
            $table->string('provinsi')->nullable();
            $table->string('kota')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kelurahan')->nullable();
            $table->string('rw', 10)->nullable();
            $table->string('rt', 10)->nullable();

            // Data pengukuran
            $table->float('bb_lahir')->nullable();
            $table->float('tb_lahir')->nullable();
            $table->float('bb')->nullable();
            $table->float('tb')->nullable();
            $table->float('lila')->nullable();
            $table->float('lika')->nullable();

            // Z-score dan status
            $table->float('bb_u')->nullable();
            $table->float('zs_bb_u')->nullable();
            $table->float('tb_u')->nullable();
            $table->float('zs_tb_u')->nullable();
            $table->float('bb_tb')->nullable();
            $table->float('zs_bb_tb')->nullable();
            $table->boolean('naik_berat_badan')->nullable();

            // Kondisi & perilaku kesehatan
            $table->string('asi')->nullable();
            $table->string('imunisasi')->nullable();
            $table->string('diasuh_oleh')->nullable();
            $table->string('rutin_posyandu')->nullable();

            // Riwayat penyakit
            $table->string('riwayat_penyakit_bawaan')->nullable();
            $table->string('penyakit_bawaan')->nullable();
            $table->string('riwayat_penyakit_6bulan')->nullable();
            $table->string('penyakit_6bulan')->nullable();

            // Lingkungan & sanitasi
            $table->string('terpapar_asap_rokok')->nullable();
            $table->string('penggunaan_jamban_sehat')->nullable();
            $table->string('penggunaan_sab')->nullable();

            // Dukungan & catatan
            $table->string('apabila_ada_penyakit')->nullable();
            $table->string('memiliki_jaminan')->nullable();
            $table->string('kie')->nullable();
            $table->string('mendapatkan_bantuan')->nullable();
            $table->text('catatan')->nullable();

            // Keluarga
            $table->string('no_kk')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pendampingan_anak');
    }
};

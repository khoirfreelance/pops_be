<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_pregnancy', function (Blueprint $table) {
            $table->id();
            $table->string('nama_petugas')->nullable();
            $table->date('tanggal_pendampingan')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->string('nik_ibu')->nullable();
            $table->integer('usia_ibu')->nullable();
            $table->string('nama_suami')->nullable();
            $table->string('nik_suami')->nullable();
            $table->string('pekerjaan_suami')->nullable();
            $table->integer('usia_suami')->nullable();
            $table->string('kehamilan_ke')->nullable();
            $table->integer('jumlah_anak')->nullable();
            $table->string('status_kehamilan')->nullable();
            $table->string('riwayat_4t')->nullable();
            $table->string('riwayat_penggunaan_kb')->nullable();
            $table->string('riwayat_ber_kontrasepsi')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('desa')->nullable();
            $table->string('rt')->nullable();
            $table->string('rw')->nullable();
            $table->date('tanggal_pemeriksaan_terakhir')->nullable();
            $table->decimal('berat_badan', 5, 2)->nullable();
            $table->decimal('tinggi_badan', 5, 2)->nullable();
            $table->decimal('imt', 5, 2)->nullable();
            $table->decimal('kadar_hb', 5, 2)->nullable();
            $table->string('status_gizi_hb')->nullable();
            $table->decimal('lila', 5, 2)->nullable();
            $table->string('status_gizi_lila')->nullable();
            $table->string('riwayat_penyakit')->nullable();
            $table->integer('usia_kehamilan_minggu')->nullable();
            $table->string('taksiran_berat_janin')->nullable();
            $table->string('tinggi_fundus')->nullable();
            $table->date('hpl')->nullable();
            $table->string('terpapar_asap_rokok')->nullable();
            $table->string('mendapat_ttd')->nullable();
            $table->string('menggunakan_jamban')->nullable();
            $table->string('menggunakan_sab')->nullable();
            $table->string('fasilitas_rujukan')->nullable();
            $table->string('riwayat_keguguran_iufd')->nullable();
            $table->string('mendapat_kie')->nullable();
            $table->string('mendapat_bantuan_sosial')->nullable();
            $table->string('rencana_tempat_melahirkan')->nullable();
            $table->string('rencana_asi_eksklusif')->nullable();
            $table->string('rencana_tinggal_setelah')->nullable();
            $table->string('rencana_kontrasepsi')->nullable();
            $table->timestamps();

            $table->unique(['nik_ibu']); // contoh: mencegah duplikat by NIK ibu
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_pregnancy');
    }
};

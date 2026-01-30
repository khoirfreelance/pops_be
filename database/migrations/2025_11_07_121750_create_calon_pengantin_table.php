<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCalonPengantinTable extends Migration
{
    public function up()
    {
        Schema::create('tb_calon_pengantin', function (Blueprint $table) {
            $table->id();
            $table->string('nama_petugas')->nullable();
            $table->date('tanggal_pendampingan')->nullable();

            // Perempuan
            $table->string('nama_perempuan')->nullable();
            $table->string('nik_perempuan')->nullable()->index();
            $table->string('pekerjaan_perempuan')->nullable();
            $table->unsignedTinyInteger('usia_perempuan')->nullable();
            $table->string('hp_perempuan')->nullable();

            // Laki-laki
            $table->string('nama_laki')->nullable();
            $table->string('nik_laki')->nullable()->index();
            $table->string('pekerjaan_laki')->nullable();
            $table->unsignedTinyInteger('usia_laki')->nullable();
            $table->string('hp_laki')->nullable();

            $table->unsignedTinyInteger('pernikahan_ke')->nullable();

            // Lokasi
            $table->string('provinsi')->nullable();
            $table->string('kota')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('desa')->nullable();
            $table->string('rw')->nullable();
            $table->string('rt')->nullable();
            $table->string('posyandu')->nullable();

            // Pemeriksaan
            $table->date('status_risiko')->nullable();
            $table->date('tanggal_pemeriksaan')->nullable();
            $table->decimal('berat_perempuan', 5, 2)->nullable();
            $table->unsignedSmallInteger('tinggi_perempuan')->nullable();
            $table->decimal('imt_perempuan', 5, 2)->nullable();
            $table->decimal('hb_perempuan', 5, 2)->nullable();
            $table->string('status_hb')->nullable();
            $table->decimal('lila_perempuan', 5, 2)->nullable();
            $table->string('status_kek')->nullable();

            // Kondisi & fasilitas (boolean represented as tinyint)
            $table->boolean('terpapar_rokok')->default(false);
            $table->boolean('mendapat_ttd')->default(false);
            $table->boolean('menggunakan_jamban')->default(false);
            $table->boolean('sumber_air_bersih')->default(false);
            $table->boolean('punya_riwayat_penyakit')->default(false);
            $table->text('riwayat_penyakit')->nullable();
            $table->boolean('mendapat_fasilitas_rujukan')->default(false);
            $table->boolean('mendapat_kie')->default(false);
            $table->boolean('mendapat_bantuan_pmt')->default(false);

            $table->date('tanggal_rencana_menikah')->nullable();
            $table->string('rencana_tinggal')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('tb_calon_pengantin');
    }
}

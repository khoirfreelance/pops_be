<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervensi', function (Blueprint $table) {
            $table->id();
            $table->string('petugas')->nullable();
            $table->date('tgl_intervensi')->nullable();
            $table->string('desa')->nullable();
            $table->string('nama_subjek')->nullable();
            $table->string('nik_subjek', 20)->nullable();
            $table->string('status_subjek')->nullable();
            $table->string('jk', 10)->nullable();
            $table->date('tgl_lahir')->nullable();
            $table->string('nama_wali')->nullable();
            $table->string('nik_wali', 20)->nullable();
            $table->string('status_wali', 10)->nullable(); // Ayah/Ibu
            $table->string('rt', 10)->nullable();
            $table->string('rw', 10)->nullable();
            $table->string('posyandu')->nullable();
            $table->string('umur_subjek', 10)->nullable();
            $table->string('bantuan')->nullable();
            $table->string('kategori')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervensi');
    }
};

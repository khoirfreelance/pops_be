<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Child extends Model
{
    use HasFactory;

    protected $table = 'pendampingan_anak';

    protected $fillable = [
        'petugas',
        'tgl_pendampingan',
        'nama_anak',
        'nik_anak',
        'jk',
        'tgl_lahir',
        'usia',
        'nama_ayah',
        'nik_ayah',
        'pekerjaan_ayah',
        'usia_ayah',
        'nama_ibu',
        'nik_ibu',
        'pekerjaan_ibu',
        'usia_ibu',
        'anak_ke',
        'riwayat_4t',
        'riwayat_kb',
        'alat_kontrasepsi',
        'provinsi',
        'kota',
        'kecamatan',
        'kelurahan',
        'rw',
        'rt',
        'bb_lahir',
        'tb_lahir',
        'bb',
        'tb',
        'lila',
        'lika',
        'bb_u',
        'zs_bb_u',
        'tb_u',
        'zs_tb_u',
        'bb_tb',
        'zs_bb_tb',
        'naik_berat_badan',
        'asi',
        'imunisasi',
        'diasuh_oleh',
        'rutin_posyandu',
        'riwayat_penyakit_bawaan',
        'penyakit_bawaan',
        'riwayat_penyakit_6bulan',
        'penyakit_6bulan',
        'terpapar_asap_rokok',
        'penggunaan_jamban_sehat',
        'penggunaan_sab',
        'apabila_ada_penyakit',
        'memiliki_jaminan',
        'kie',
        'mendapatkan_bantuan',
        'catatan',
        'no_kk'
    ];
}

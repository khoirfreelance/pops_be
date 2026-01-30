<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Kunjungan extends Model
{
    use HasFactory;

    protected $table = 'kunjungan_anak';

    protected $fillable = [
        'petugas',
        'nik',
        'nama_anak',
        'jk',
        'tgl_lahir',
        'bb_lahir',
        'tb_lahir',
        'nama_ortu',
        'peran',
        'nik_ortu',
        'alamat',
        'provinsi',
        'kota',
        'kecamatan',
        'kelurahan',
        'rw',
        'rt',
        'puskesmas',
        'posyandu',
        'tgl_pengukuran',
        'usia_saat_ukur',
        'bb',
        'tb',
        'lila',
        'bb_u',
        'zs_bb_u',
        'tb_u',
        'zs_tb_u',
        'bb_tb',
        'zs_bb_tb',
        'naik_berat_badan',
        'diasuh_oleh',
        'asi',
        'imunisasi',
        'rutin_posyandu',
        'penyakit_bawaan',
        'penyakit_6bulan',
        'terpapar_asap_rokok',
        'penggunaan_jamban_sehat',
        'penggunaan_sab',
        'memiliki_jaminan',
        'kie',
        'mendapatkan_bantuan',
        'catatan',
        'kpsp',
        'no_kk'
    ];

    protected $dates = ['tgl_pengukuran'];
    # atau
    protected $casts = [
        'tgl_pengukuran' => 'datetime:Y-m-d',
    ];

    public function pendampingan()
    {
        return $this->hasMany(Child::class, 'nik_anak', 'nik');
    }

    public function intervensi()
    {
        return $this->hasMany(Intervensi::class, 'nik_subjek', 'nik');
    }
}

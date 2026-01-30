<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Catin extends Model
{
    protected $table = 'calon_pengantin';

    protected $fillable = [
        'nama_petugas',
        'tanggal_pendampingan',
        'nama_perempuan',
        'nik_perempuan',
        'pekerjaan_perempuan',
        'usia_perempuan',
        'hp_perempuan',
        'nama_laki',
        'nik_laki',
        'pekerjaan_laki',
        'usia_laki',
        'hp_laki',
        'pernikahan_ke',
        'provinsi',
        'kota',
        'kecamatan',
        'kelurahan',
        'rw',
        'rt',
        'posyandu',
        'status_risiko',
        'tanggal_pemeriksaan',
        'berat_perempuan',
        'tinggi_perempuan',
        'imt_perempuan',
        'hb_perempuan',
        'status_hb',
        'lila_perempuan',
        'status_kek',
        'terpapar_rokok',
        'mendapat_ttd',
        'menggunakan_jamban',
        'sumber_air_bersih',
        'punya_riwayat_penyakit',
        'riwayat_penyakit',
        'mendapat_fasilitas_rujukan',
        'mendapat_kie',
        'mendapat_bantuan_pmt',
        'tanggal_rencana_menikah',
        'rencana_tinggal',
    ];

    protected $casts = [
        'tanggal_pendampingan' => 'date',
        'tanggal_pemeriksaan' => 'date',
        'tanggal_rencana_menikah' => 'date',
        'terpapar_rokok' => 'boolean',
        'mendapat_ttd' => 'boolean',
        'menggunakan_jamban' => 'boolean',
        'sumber_air_bersih' => 'boolean',
        'punya_riwayat_penyakit' => 'boolean',
        'mendapat_fasilitas_rujukan' => 'boolean',
        'mendapat_kie' => 'boolean',
        'mendapat_bantuan_pmt' => 'boolean',
    ];
}
